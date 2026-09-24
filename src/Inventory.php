<?php

declare(strict_types = 1);

namespace Centrex\Inventory;

use Centrex\Accounting\Models\{Bill, Invoice};
use Centrex\Inventory\Enums\{PurchaseOrderStatus, SaleOrderStatus};
use Centrex\Inventory\Exceptions\InvalidTransitionException;
use Centrex\Inventory\Models\{Adjustment, Customer, CustomerProductStat, Product, ProductTrendSnapshot, PurchaseOrder, SaleOrder, Supplier, SupplierProductStat, Transfer, Warehouse};
use Centrex\Inventory\Support\CommercialTeamAccess;
use Centrex\ModelData\Models\Data;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Gate, Schema};

/**
 * Central service class for all inventory operations.
 *
 * This is the class behind the {@see Facades\Inventory} facade.
 * It is organised into logical sections — each separated by a comment banner:
 *
 *   Exchange Rates      – set / get / convert currency rates
 *   Price Management    – set, resolve, and list product prices per tier
 *   Stock Ledger        – read on-hand, reserved, and in-transit quantities
 *   WAC Engine          – internal weighted-average-cost recalculation helpers
 *   Purchase Orders     – create, submit, confirm, receive, cancel
 *   Stock Receipts      – create / post / void goods-received notes (GRN)
 *   Sale Orders         – create, confirm, reserve, fulfil, cancel, quotation convert
 *   Returns             – customer returns (SO) and supplier returns (PO)
 *   Transfers           – inter-warehouse stock moves
 *   Adjustments         – cycle-count / write-off stock corrections
 *   Coupons             – discount code resolution
 *   Entity Management   – warehouses, products, customers, suppliers, partners
 *   Reports & Analytics – stock valuation, movement history, sales forecast
 *   Financing           – inventory financing and loan facility tracking
 *   Internal Helpers    – document numbering, metadata, WAC writes, etc.
 */
class Inventory
{
    use Concerns\GeneratesInventoryReports;
    use Concerns\GeneratesSalesForecast;
    use Concerns\HasSharedInventoryHelpers;
    use Concerns\ManagesAdjustments;
    use Concerns\ManagesExchangeRates;
    use Concerns\ManagesInterWarehouseShipments;
    use Concerns\ManagesPickPackShip;
    use Concerns\ManagesPricing;
    use Concerns\ManagesPurchaseOrders;
    use Concerns\ManagesReturns;
    use Concerns\ManagesSaleOrderLifecycle;
    use Concerns\ManagesSaleOrders;
    use Concerns\ManagesStockLedger;
    use Concerns\ManagesStockReceipts;
    use Concerns\ManagesTransfers;
    use Concerns\QueriesInventoryEntities;

    /**
     * Memoised `inventory.qty_tolerance`, keyed by application instance.
     *
     * The tolerance is consulted on nearly every quantity comparison, including inside the
     * per-line loops of the receipt, fulfilment, transfer and adjustment paths — so it was
     * being re-read from config dozens of times per posted document, each read a container
     * resolve plus a dotted-key lookup. Keying by container identity keeps a rebuilt
     * application (Testbench, Octane) from inheriting the previous one's value.
     *
     * @var array<int, float>
     */
    private static array $qtyTolerances = [];

    /** Quantity comparison tolerance, used to absorb float rounding on decimal quantities. */
    private function qtyTolerance(): float
    {
        return self::$qtyTolerances[spl_object_id(app())] ??= (float) config('inventory.qty_tolerance', 0.0001);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function historicalCustomerCollectionRatio(DateTimeInterface $startDate, DateTimeInterface $endDate): float
    {
        $invoiceClass = Invoice::class;

        if (!class_exists($invoiceClass)) {
            return 0.8;
        }

        $invoices = $invoiceClass::query()
            ->where(function ($query): void {
                $query->where('source_type', SaleOrder::class)
                    ->orWhereNotNull('inventory_sale_order_id');
            })
            ->where('invoice_date', '>=', $startDate->toDateString())
            ->where('invoice_date', '<=', $endDate->toDateString())
            ->get();

        $total = (float) $invoices->sum('base_total');

        if ($total <= 0) {
            return 0.8;
        }

        return max(0.1, min(1.0, round((float) $invoices->sum('base_paid_amount') / $total, 4)));
    }

    private function historicalSupplierPaymentRatio(DateTimeInterface $startDate, DateTimeInterface $endDate): float
    {
        $billClass = Bill::class;

        if (!class_exists($billClass)) {
            return 0.7;
        }

        $bills = $billClass::query()
            ->where(function ($query): void {
                $query->where('source_type', PurchaseOrder::class)
                    ->orWhereNotNull('inventory_purchase_order_id');
            })
            ->where('bill_date', '>=', $startDate->toDateString())
            ->where('bill_date', '<=', $endDate->toDateString())
            ->get();

        $total = (float) $bills->sum('base_total');

        if ($total <= 0) {
            return 0.7;
        }

        return max(0.1, min(1.0, round((float) $bills->sum('base_paid_amount') / $total, 4)));
    }

    private function buildForecastTimeline(
        Collection $productForecast,
        int $forecastDays,
        float $collectionRatio,
        float $supplierPaymentRatio,
    ): array {
        $months = max(3, (int) ceil($forecastDays / 30));
        $categories = [];
        $qtySeries = [];
        $revenueSeries = [];
        $cashInSeries = [];
        $cashOutSeries = [];
        $netSeries = [];
        $forecastEnd = now()->copy()->addDays($forecastDays);

        for ($offset = 0; $offset < $months; $offset++) {
            $monthStart = now()->copy()->startOfMonth()->addMonths($offset);
            $monthEnd = $monthStart->copy()->endOfMonth();
            $periodEnd = $monthEnd->lessThan($forecastEnd) ? $monthEnd : $forecastEnd;
            $days = (float) $monthStart->diffInDaysFiltered(
                fn ($date): bool => $date <= $periodEnd,
                $monthEnd->copy()->addDay(),
            );

            if ($days <= 0) {
                break;
            }

            $monthQty = round((float) $productForecast->sum(fn (array $product): float => (float) $product['avg_daily_qty'] * $days), 2);
            $monthRevenue = round((float) $productForecast->sum(fn (array $product): float => (float) $product['avg_daily_revenue'] * $days), 2);
            $monthOutflow = round((float) $productForecast->sum(function (array $product) use ($forecastDays, $days): float {
                $gapQty = (float) $product['forecast_gap_qty'];

                if ($gapQty <= 0 || $forecastDays <= 0) {
                    return 0.0;
                }

                return ($gapQty / $forecastDays) * $days * ((float) $product['forecast_procurement_cost'] / max(0.0001, $gapQty));
            }), 2);
            $monthCashIn = round($monthRevenue * $collectionRatio, 2);
            $monthCashOut = round($monthOutflow * $supplierPaymentRatio, 2);

            $categories[] = $monthStart->format('M Y');
            $qtySeries[] = $monthQty;
            $revenueSeries[] = $monthRevenue;
            $cashInSeries[] = $monthCashIn;
            $cashOutSeries[] = $monthCashOut;
            $netSeries[] = round($monthCashIn - $monthCashOut, 2);
        }

        return [
            'categories' => $categories,
            'series'     => [
                ['name' => 'Forecast Qty', 'data' => $qtySeries],
                ['name' => 'Forecast Revenue', 'data' => $revenueSeries],
                ['name' => 'Cash In', 'data' => $cashInSeries],
                ['name' => 'Cash Out', 'data' => $cashOutSeries],
                ['name' => 'Net Cash', 'data' => $netSeries],
            ],
            'totals' => [
                'qty'      => round(array_sum($qtySeries), 2),
                'revenue'  => round(array_sum($revenueSeries), 2),
                'cash_in'  => round(array_sum($cashInSeries), 2),
                'cash_out' => round(array_sum($cashOutSeries), 2),
                'cash_net' => round(array_sum($netSeries), 2),
            ],
        ];
    }

    private function assertTransition(mixed $current, mixed $target, string $subject): void
    {
        if (!$current->canTransitionTo($target)) {
            throw new InvalidTransitionException("Cannot transition {$subject} from [{$current->value}] to [{$target->value}].");
        }
    }

    private function normalizeTransferBoxes(array $data): array
    {
        if (!empty($data['boxes'])) {
            return array_values($data['boxes']);
        }

        if (empty($data['items'])) {
            throw new \InvalidArgumentException('At least one transfer box or product line is required.');
        }

        $measuredWeight = 0.0;

        foreach ($data['items'] as $item) {
            $product = Product::findOrFail($item['product_id']);
            $qty = round((float) $item['qty_sent'], 4);
            $this->ensurePositiveQuantity($qty, 'qty_sent');
            $measuredWeight += $product->weight_kg !== null
                ? round($qty * (float) $product->weight_kg, 4)
                : 0.0;
        }

        return [[
            '_derived'           => true,
            'box_code'           => 'BOX-001',
            'measured_weight_kg' => round($measuredWeight, 4),
            'notes'              => $data['notes'] ?? null,
            'items'              => $data['items'],
        ]];
    }

    private function resolveCreditOverride(?Customer $customer, float $newOrderAmount, array $data): array
    {
        if (!$customer) {
            return [
                'credit_limit_amount'           => 0.0,
                'credit_exposure_before_amount' => 0.0,
                'credit_exposure_after_amount'  => 0.0,
                'credit_override_required'      => false,
                'credit_override_approved_by'   => null,
                'credit_override_approved_at'   => null,
                'credit_override_notes'         => null,
            ];
        }

        $creditLimit = round((float) $customer->credit_limit_amount, 4);
        $creditExposureBefore = $this->customerOutstandingExposure($customer->id);
        $creditExposureAfter = round($creditExposureBefore + $newOrderAmount, 4);
        $limitBreached = $creditLimit > 0
            ? $creditExposureAfter > $creditLimit + $this->qtyTolerance()
            : $creditExposureAfter > $this->qtyTolerance();

        if (!$limitBreached) {
            return [
                'credit_limit_amount'           => $creditLimit,
                'credit_exposure_before_amount' => $creditExposureBefore,
                'credit_exposure_after_amount'  => $creditExposureAfter,
                'credit_override_required'      => false,
                'credit_override_approved_by'   => null,
                'credit_override_approved_at'   => null,
                'credit_override_notes'         => null,
            ];
        }

        // Credit limit breached: flag the order for review rather than blocking it.
        // Approve automatically only when the submitter holds the approve-credit gate.
        $approvedBy = $data['credit_override_approved_by'] ?? $data['created_by'] ?? $this->currentUserId();
        $canApprove = $this->canApproveCreditOverride($approvedBy);

        return [
            'credit_limit_amount'           => $creditLimit,
            'credit_exposure_before_amount' => $creditExposureBefore,
            'credit_exposure_after_amount'  => $creditExposureAfter,
            'credit_override_required'      => true,
            'credit_override_approved_by'   => $canApprove ? $approvedBy : null,
            'credit_override_approved_at'   => $canApprove ? now() : null,
            'credit_override_notes'         => $data['credit_override_notes'] ?? null,
        ];
    }

    private function customerOutstandingExposure(int $customerId): float
    {
        // Open orders (confirmed but not yet delivered): always count due_amount.
        // due_amount is set at creation (= total_amount) and reduced by InvoicePaymentObserver
        // whenever a linked accounting invoice receives a payment.
        $openStatuses = [
            SaleOrderStatus::CONFIRMED->value,
            SaleOrderStatus::PROCESSING->value,
            SaleOrderStatus::PARTIAL->value,
        ];

        $openExposure = (float) SaleOrder::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', $openStatuses)
            ->sum('due_amount');

        // FULFILLED orders: only count those with a linked accounting invoice.
        // Without an invoice the goods were delivered without credit (COD/cash),
        // so there is no outstanding receivable to track.
        // due_amount on these rows is kept current by InvoicePaymentObserver.
        $fulfilledExposure = (float) SaleOrder::query()
            ->where('customer_id', $customerId)
            ->where('status', SaleOrderStatus::FULFILLED->value)
            ->whereNotNull('accounting_invoice_id')
            ->sum('due_amount');

        return round($openExposure + $fulfilledExposure, 4);
    }

    private function supplierOutstandingExposure(int $supplierId): float
    {
        // Open purchase orders (confirmed but not yet fully received): always count due_amount.
        // due_amount is set at creation (= total_amount) and reduced by BillPaymentObserver
        // whenever a linked accounting bill receives a payment.
        $openStatuses = [
            PurchaseOrderStatus::CONFIRMED->value,
            PurchaseOrderStatus::PARTIAL->value,
        ];

        $openExposure = (float) PurchaseOrder::query()
            ->where('supplier_id', $supplierId)
            ->whereIn('status', $openStatuses)
            ->sum('due_amount');

        // RECEIVED orders: only count those with a linked accounting bill.
        // Without a bill the goods were received without credit terms, so there is no
        // outstanding payable to track. due_amount on these rows is kept current by
        // BillPaymentObserver.
        $receivedExposure = (float) PurchaseOrder::query()
            ->where('supplier_id', $supplierId)
            ->where('status', PurchaseOrderStatus::RECEIVED->value)
            ->whereNotNull('accounting_bill_id')
            ->sum('due_amount');

        return round($openExposure + $receivedExposure, 4);
    }

    private function canApproveCreditOverride(?int $approvedBy): bool
    {
        if (auth()->check()) {
            return Gate::forUser(auth()->user())->allows('inventory.sale-orders.approve-credit');
        }

        return $approvedBy !== null;
    }

    private function currentUserId(): ?int
    {
        $user = auth()->user();

        if (!$user || !method_exists($user, 'getAuthIdentifier')) {
            return null;
        }

        return (int) $user->getAuthIdentifier();
    }

    private function defaultPurchaseWarehouseId(): int
    {
        $name = (string) config('inventory.purchase_defaults.warehouse_name', 'UK');

        $warehouse = Warehouse::query()->where('name', $name)->first();

        if (!$warehouse) {
            throw new \RuntimeException("Default purchase warehouse [{$name}] not found.");
        }

        return (int) $warehouse->id;
    }

    private function defaultSaleWarehouseId(): int
    {
        $name = (string) config('inventory.sale_defaults.warehouse_name', 'UK');

        $warehouse = Warehouse::query()->where('name', $name)->first();

        if (!$warehouse) {
            throw new \RuntimeException("Default sale warehouse [{$name}] not found.");
        }

        return (int) $warehouse->id;
    }

    private function salesAssignment(array $data, ?Customer $customer, ?int $createdBy): array
    {
        $assignment = CommercialTeamAccess::assignmentFor('sales', $createdBy);
        $explicit = array_filter([
            'sales_manager_id'           => $data['sales_manager_id'] ?? $customer?->sales_manager_id ?? null,
            'sales_assistant_manager_id' => $data['sales_assistant_manager_id'] ?? $customer?->sales_assistant_manager_id ?? null,
            'sales_executive_id'         => $data['sales_executive_id'] ?? $customer?->sales_executive_id ?? null,
        ], fn ($value): bool => $value !== null);

        return array_replace($assignment, $explicit);
    }

    private function purchaseAssignment(array $data, ?int $createdBy): array
    {
        $supplier = isset($data['supplier_id']) ? Supplier::query()->find((int) $data['supplier_id']) : null;
        $assignment = CommercialTeamAccess::assignmentFor('purchase', $createdBy);
        $explicit = array_filter([
            'purchase_manager_id'           => $data['purchase_manager_id'] ?? $supplier?->purchase_manager_id ?? null,
            'purchase_assistant_manager_id' => $data['purchase_assistant_manager_id'] ?? $supplier?->purchase_assistant_manager_id ?? null,
            'purchase_executive_id'         => $data['purchase_executive_id'] ?? $supplier?->purchase_executive_id ?? null,
        ], fn ($value): bool => $value !== null);

        return array_replace($assignment, $explicit);
    }

    private function customerSegment(float $revenue, int $ordersCount): string
    {
        return match (true) {
            $revenue >= 500000 || $ordersCount >= 20 => 'Strategic',
            $revenue >= 100000 || $ordersCount >= 6  => 'Growth',
            $ordersCount > 1                         => 'Repeat',
            default                                  => 'New',
        };
    }

    private function assertSaleOrderAccess(SaleOrder $saleOrder): void
    {
        $visibleUserIds = CommercialTeamAccess::visibleUserIds('sales');

        if ($visibleUserIds === null) {
            return;
        }

        $ownerIds = collect([
            $saleOrder->created_by,
            $saleOrder->sales_manager_id,
            $saleOrder->sales_assistant_manager_id,
            $saleOrder->sales_executive_id,
        ])->filter()->map(fn ($id): int => (int) $id)->all();

        abort_unless(count(array_intersect($visibleUserIds, $ownerIds)) > 0, 403);
    }

    private function assertPurchaseOrderAccess(PurchaseOrder $purchaseOrder): void
    {
        $visibleUserIds = CommercialTeamAccess::visibleUserIds('purchase');

        if ($visibleUserIds === null) {
            return;
        }

        $ownerIds = collect([
            $purchaseOrder->created_by,
            $purchaseOrder->purchase_manager_id,
            $purchaseOrder->purchase_assistant_manager_id,
            $purchaseOrder->purchase_executive_id,
        ])->filter()->map(fn ($id): int => (int) $id)->all();

        abort_unless(count(array_intersect($visibleUserIds, $ownerIds)) > 0, 403);
    }

    private function normalizeSaleDocumentType(?string $documentType): string
    {
        return $documentType === 'quotation' ? 'quotation' : 'order';
    }

    private function normalizePurchaseDocumentType(?string $documentType): string
    {
        return $documentType === 'requisition' ? 'requisition' : 'order';
    }

    private function appendConversionNote(?string $notes, string $line): string
    {
        return collect([$notes, $line])
            ->filter(fn (?string $value): bool => filled($value))
            ->implode("\n\n");
    }

    private function modelDataReady(): bool
    {
        return class_exists(Data::class)
            && Schema::hasTable('model_datas');
    }

    private function documentMetadata(Model $model): array
    {
        if (!$this->modelDataReady()) {
            return [];
        }

        $record = Data::query()
            ->forModel($model)
            ->first();

        if (!$record) {
            return [];
        }

        return is_array($record->data)
            ? $record->data
            : (json_decode((string) $record->data, true) ?: []);
    }

    private function putDocumentMetadata(Model $model, array $metadata): void
    {
        if (!$this->modelDataReady()) {
            return;
        }

        Data::putForModel($model, $metadata);
    }

    // -------------------------------------------------------------------------
    // Partner Management
    // -------------------------------------------------------------------------

    /**
     * Create a new API partner (dropshipper / e-commerce / B2B / marketplace).
     * Returns the partner with the generated api_key (shown only once).
     */
    public function createPartner(array $data): Partner
    {
        return Partner::create([
            'name'                  => $data['name'],
            'type'                  => $data['type'] ?? 'dropshipper',
            'api_key'               => Partner::generateApiKey(),
            'customer_id'           => $data['customer_id'] ?? null,
            'default_warehouse_id'  => $data['default_warehouse_id'] ?? null,
            'default_price_tier'    => $data['default_price_tier'] ?? 'B2B_WHOLESALE',
            'can_view_stock'        => $data['can_view_stock'] ?? true,
            'can_view_prices'       => $data['can_view_prices'] ?? true,
            'can_create_orders'     => $data['can_create_orders'] ?? true,
            'is_active'             => $data['is_active'] ?? true,
            'allowed_warehouse_ids' => $data['allowed_warehouse_ids'] ?? null,
            'allowed_product_ids'   => $data['allowed_product_ids'] ?? null,
        ]);
    }

    public function updatePartner(int $partnerId, array $data): Partner
    {
        $partner = Partner::findOrFail($partnerId);
        $partner->update($data);

        return $partner->refresh();
    }

    /**
     * Rotate the API key for a partner. Only the hash is persisted — the returned model's
     * getPlainApiKey() holds the new plaintext key for exactly this one response.
     */
    public function rotatePartnerApiKey(int $partnerId): Partner
    {
        $partner = Partner::findOrFail($partnerId);
        $partner->update(['api_key' => Partner::generateApiKey()]);

        return $partner;
    }

    public function listPartners(bool $activeOnly = true): Collection
    {
        return Partner::when($activeOnly, fn ($q) => $q->where('is_active', true))->get();
    }

    // -------------------------------------------------------------------------
    // Product Trend & Profitability Analytics
    // -------------------------------------------------------------------------

    /**
     * Returns trend snapshots for a single product, ordered chronologically.
     *
     * @return array{product_id: int, period: string, snapshots: Collection, trend: array}
     */
    public function productTrends(
        int $productId,
        string $period = 'daily',
        int $days = 30,
        ?int $warehouseId = null,
    ): array {
        $from = now()->subDays($days)->startOfDay();

        $snapshots = ProductTrendSnapshot::query()
            ->where('product_id', $productId)
            ->where('period', $period)
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->where('snapshot_date', '>=', $from->toDateString())
            ->orderBy('snapshot_date')
            ->get();

        $revenues = $snapshots->pluck('revenue_amount')->map(fn ($v) => (float) $v);
        $margins = $snapshots->pluck('gross_margin_pct')->map(fn ($v) => (float) $v);
        $qtys = $snapshots->pluck('qty_sold')->map(fn ($v) => (float) $v);

        return [
            'product_id' => $productId,
            'period'     => $period,
            'days'       => $days,
            'snapshots'  => $snapshots,
            'trend'      => [
                'revenue_slope'      => $this->linearSlope($revenues->values()->all()),
                'qty_slope'          => $this->linearSlope($qtys->values()->all()),
                'avg_gross_margin'   => $margins->avg() !== null ? round((float) $margins->avg(), 2) : null,
                'peak_revenue_date'  => $snapshots->sortByDesc('revenue_amount')->first()?->snapshot_date?->toDateString(),
                'total_revenue'      => round((float) $revenues->sum(), 2),
                'total_qty'          => round((float) $qtys->sum(), 2),
                'total_cogs'         => round((float) $snapshots->sum('cogs_amount'), 2),
                'total_gross_profit' => round((float) $snapshots->sum('gross_profit_amount'), 2),
            ],
        ];
    }

    /**
     * Per-product purchase statistics for a customer — useful for churn detection and reorder forecasting.
     *
     * @return array{customer_id: int, stats: Collection, top_products: Collection}
     */
    public function customerProductStats(int $customerId): array
    {
        $stats = CustomerProductStat::query()
            ->with(['product', 'variant'])
            ->where('customer_id', $customerId)
            ->orderByDesc('total_revenue_amount')
            ->get();

        $top = $stats->take(10)->map(fn (CustomerProductStat $s): array => [
            'product_id'              => $s->product_id,
            'product_name'            => $s->product?->name ?? ('#' . $s->product_id),
            'sku'                     => $s->product?->sku,
            'variant_id'              => $s->variant_id,
            'total_orders'            => $s->total_orders,
            'total_qty_ordered'       => $s->total_qty_ordered,
            'total_revenue_amount'    => $s->total_revenue_amount,
            'avg_unit_price_amount'   => $s->avg_unit_price_amount,
            'avg_order_interval_days' => $s->avg_order_interval_days,
            'return_rate_pct'         => $s->return_rate_pct,
            'first_ordered_at'        => $s->first_ordered_at?->toDateString(),
            'last_ordered_at'         => $s->last_ordered_at?->toDateString(),
            'days_since_last_order'   => $s->last_ordered_at ? $s->last_ordered_at->diffInDays(now()) : null,
            'reorder_due'             => $s->avg_order_interval_days !== null && $s->last_ordered_at
                ? $s->last_ordered_at->addDays((int) ceil((float) $s->avg_order_interval_days))->toDateString()
                : null,
        ]);

        return [
            'customer_id'  => $customerId,
            'stats'        => $stats,
            'top_products' => $top,
        ];
    }

    /**
     * Per-product supply statistics for a supplier — cost trend, lead time, reliability.
     *
     * @return array{supplier_id: int, stats: Collection, top_products: Collection}
     */
    public function supplierProductStats(int $supplierId): array
    {
        $stats = SupplierProductStat::query()
            ->with(['product', 'variant'])
            ->where('supplier_id', $supplierId)
            ->orderByDesc('total_cost_amount')
            ->get();

        $top = $stats->take(10)->map(fn (SupplierProductStat $s): array => [
            'product_id'           => $s->product_id,
            'product_name'         => $s->product?->name ?? ('#' . $s->product_id),
            'sku'                  => $s->product?->sku,
            'variant_id'           => $s->variant_id,
            'total_orders'         => $s->total_orders,
            'total_qty_ordered'    => $s->total_qty_ordered,
            'total_qty_received'   => $s->total_qty_received,
            'total_cost_amount'    => $s->total_cost_amount,
            'avg_unit_cost_amount' => $s->avg_unit_cost_amount,
            'min_unit_cost_amount' => $s->min_unit_cost_amount,
            'max_unit_cost_amount' => $s->max_unit_cost_amount,
            'cost_spread_pct'      => $s->min_unit_cost_amount > 0
                ? round(($s->max_unit_cost_amount - $s->min_unit_cost_amount) / $s->min_unit_cost_amount * 100, 2)
                : 0.0,
            'avg_lead_time_days'       => $s->avg_lead_time_days,
            'on_time_receipt_rate_pct' => $s->on_time_receipt_rate_pct,
            'fulfillment_rate_pct'     => $s->fulfillment_rate_pct,
            'first_ordered_at'         => $s->first_ordered_at?->toDateString(),
            'last_ordered_at'          => $s->last_ordered_at?->toDateString(),
        ]);

        return [
            'supplier_id'  => $supplierId,
            'stats'        => $stats,
            'top_products' => $top,
        ];
    }

    /**
     * Product profitability report over a date range.
     *
     * Groups trend snapshots by product (or category/brand via $groupBy)
     * and ranks by gross profit, margin, and revenue to surface the most
     * and least profitable items.
     *
     * @param  string  $groupBy  'product' | 'category' | 'brand'
     * @return array{from: string, to: string, group_by: string, rows: Collection, summary: array}
     */
    public function profitabilityReport(
        string $from,
        string $to,
        string $groupBy = 'product',
        ?int $warehouseId = null,
    ): array {
        $rows = ProductTrendSnapshot::query()
            ->with('product.category', 'product.brand')
            ->where('period', 'daily')
            ->whereBetween('snapshot_date', [$from, $to])
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->get()
            ->groupBy(function (ProductTrendSnapshot $snap) use ($groupBy): int|string {
                return match ($groupBy) {
                    'category' => $snap->product?->category_id ?? 'Uncategorized',
                    'brand'    => $snap->product?->brand_id ?? 'No Brand',
                    default    => $snap->product_id,
                };
            })
            ->map(function (Collection $snaps, int|string $key) use ($groupBy): array {
                $first = $snaps->first();
                $revenue = (float) $snaps->sum('revenue_amount');
                $cogs = (float) $snaps->sum('cogs_amount');
                $grossProfit = (float) $snaps->sum('gross_profit_amount');
                $qtySold = (float) $snaps->sum('qty_sold');
                $grossMargin = $revenue > 0 ? round($grossProfit / $revenue * 100, 2) : 0.0;

                $label = match ($groupBy) {
                    'category' => $first?->product?->category?->name ?? ('Category #' . $key),
                    'brand'    => $first?->product?->brand?->name ?? ('Brand #' . $key),
                    default    => $first?->product?->name ?? ('Product #' . $key),
                };

                return [
                    'key'              => $key,
                    'label'            => $label,
                    'sku'              => $groupBy === 'product' ? ($first?->product?->sku) : null,
                    'qty_sold'         => round($qtySold, 2),
                    'revenue_amount'   => round($revenue, 2),
                    'cogs_amount'      => round($cogs, 2),
                    'gross_profit'     => round($grossProfit, 2),
                    'gross_margin_pct' => $grossMargin,
                    'orders_count'     => (int) $snaps->sum('orders_count'),
                    'customers_count'  => $snaps->max('customers_count'),
                ];
            })
            ->sortByDesc('gross_profit')
            ->values();

        $totalRevenue = (float) $rows->sum('revenue_amount');
        $totalCogs = (float) $rows->sum('cogs_amount');
        $totalProfit = (float) $rows->sum('gross_profit');

        return [
            'from'     => $from,
            'to'       => $to,
            'group_by' => $groupBy,
            'rows'     => $rows,
            'summary'  => [
                'total_revenue'      => round($totalRevenue, 2),
                'total_cogs'         => round($totalCogs, 2),
                'total_gross_profit' => round($totalProfit, 2),
                'avg_gross_margin'   => $totalRevenue > 0 ? round($totalProfit / $totalRevenue * 100, 2) : 0.0,
                'top_product'        => $rows->first()['label'] ?? null,
                'loss_makers'        => $rows->filter(fn ($r) => (float) $r['gross_profit'] < 0)->count(),
            ],
        ];
    }

    /**
     * Least-squares linear slope for a sequence of values.
     * Positive = growing, negative = declining.
     *
     * @param  float[]  $values
     */
    private function linearSlope(array $values): ?float
    {
        $n = count($values);

        if ($n < 2) {
            return null;
        }

        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumX2 = 0.0;

        foreach ($values as $i => $y) {
            $sumX += $i;
            $sumY += $y;
            $sumXY += $i * $y;
            $sumX2 += $i * $i;
        }

        $denom = $n * $sumX2 - $sumX * $sumX;

        if ($denom == 0) {
            return 0.0;
        }

        return round(($n * $sumXY - $sumX * $sumY) / $denom, 6);
    }
}

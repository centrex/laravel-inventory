<?php

declare(strict_types = 1);

namespace Centrex\Inventory;

use Carbon\Carbon;
use Centrex\Accounting\Models\{Bill, Invoice};
use Centrex\Inventory\Enums\{PurchaseOrderStatus, SaleOrderStatus};
use Centrex\Inventory\Exceptions\InvalidTransitionException;
use Centrex\Inventory\Models\{Adjustment, Customer, CustomerProductStat, Lot, Product, ProductCategory, ProductTrendSnapshot, ProductVariant, ProductVariantAttributeType, ProductVariantAttributeValue, PurchaseOrder, PurchaseOrderItem, SaleOrder, SaleOrderItem, SerialNumber, Supplier, SupplierProductStat, Transfer, Warehouse, WarehouseProduct};
use Centrex\Inventory\Support\{CommercialTeamAccess, DayRange, InventoryEntityRegistry, SalesTargetCalculator};
use Centrex\ModelData\Models\Data;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB, Gate, Schema};

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
    // Mobile / Query helpers
    // -------------------------------------------------------------------------

    /**
     * Default active warehouse (highest priority by is_default, then name).
     */
    public function defaultWarehouse(): ?Warehouse
    {
        return Warehouse::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->first();
    }

    /**
     * List products with optional filters.
     */
    public function listProducts(
        bool $activeOnly = true,
        bool $availableOnly = false,
        ?string $search = null,
        ?int $categoryId = null,
    ): Collection {
        return Product::query()
            ->with(['category', 'brand', 'warehouseProducts', 'prices'])
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->when($availableOnly, fn ($q) => $q->whereHas('warehouseProducts', fn ($wq) => $wq->whereRaw('qty_on_hand > qty_reserved')))
            ->when($search, fn ($q) => $q->where(fn ($sq) => $sq->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->orderBy('name')
            ->get();
    }

    /**
     * Find a single product with full relations.
     */
    public function findProduct(int $id): ?Product
    {
        return Product::query()
            ->with(['category', 'brand', 'warehouseProducts', 'prices', 'variants'])
            ->find($id);
    }

    /**
     * Look up a product or variant by barcode.
     * Returns ['product' => Product, 'variant' => ProductVariant|null] or null if not found.
     */
    public function findByBarcode(string $barcode): ?array
    {
        $variant = ProductVariant::query()
            ->with(['product.category', 'product.brand', 'product.prices', 'warehouseProducts', 'attributeValues.attributeType'])
            ->where('barcode', $barcode)
            ->first();

        if ($variant !== null) {
            return ['product' => $variant->product, 'variant' => $variant];
        }

        $product = Product::query()
            ->with(['category', 'brand', 'warehouseProducts', 'prices', 'variants'])
            ->where('barcode', $barcode)
            ->first();

        return $product !== null ? ['product' => $product, 'variant' => null] : null;
    }

    // ── Lot management ────────────────────────────────────────────────────────

    /**
     * List all lots for a product/warehouse, ordered by creation date (oldest first — FIFO order).
     */
    public function getLotsByProduct(int $productId, int $warehouseId, ?int $variantId = null): Collection
    {
        return Lot::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->when($variantId !== null, fn ($q) => $q->where('variant_id', $variantId))
            ->where('qty_on_hand', '>', 0)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Find a specific lot by its number within a product/warehouse scope.
     */
    public function findLotByNumber(int $productId, int $warehouseId, string $lotNumber, ?int $variantId = null): ?Lot
    {
        return Lot::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('lot_number', $lotNumber)
            ->when($variantId !== null, fn ($q) => $q->where('variant_id', $variantId))
            ->first();
    }

    /**
     * Return all lots expiring within the given number of days.
     */
    public function getExpiringLots(int $withinDays = 30, ?int $warehouseId = null): Collection
    {
        return Lot::when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($withinDays))
            ->where('qty_on_hand', '>', 0)
            ->orderBy('expires_at')
            ->get();
    }

    /**
     * Return all available serial numbers for a product/warehouse, optionally scoped to a lot.
     */
    public function getAvailableSerialNumbers(int $productId, int $warehouseId, ?int $lotId = null): Collection
    {
        return SerialNumber::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', SerialNumber::STATUS_AVAILABLE)
            ->when($lotId !== null, fn ($q) => $q->where('lot_id', $lotId))
            ->orderBy('id')
            ->get();
    }

    // ── Variant management ────────────────────────────────────────────────────

    /**
     * List all variants for a product, optionally filtered to active-only.
     */
    public function listVariants(int $productId, bool $activeOnly = false): Collection
    {
        return ProductVariant::query()
            ->with(['attributeValues.attributeType', 'warehouseProducts'])
            ->forProduct($productId)
            ->when($activeOnly, fn ($q) => $q->active())
            ->ordered()
            ->get();
    }

    /**
     * Find a single variant with its relations.
     */
    public function findVariant(int $variantId): ?ProductVariant
    {
        return ProductVariant::query()
            ->with(['product', 'attributeValues.attributeType', 'warehouseProducts'])
            ->find($variantId);
    }

    /**
     * Create a new variant for an existing product.
     *
     * $data keys: sku, name, barcode?, weight_kg?, sort_order?, is_active?, attributes?, meta?
     *
     * Optionally pass 'attribute_values' => [[attribute_type_id, attribute_value_id], ...]
     * to populate the normalised pivot at the same time.
     */
    public function createVariant(int $productId, array $data): ProductVariant
    {
        $product = Product::query()->findOrFail($productId);

        return DB::transaction(function () use ($product, $data): ProductVariant {
            $variant = ProductVariant::create([
                'product_id' => $product->getKey(),
                'sku'        => $data['sku'],
                'name'       => $data['name'],
                'barcode'    => $data['barcode'] ?? null,
                'weight_kg'  => $data['weight_kg'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0,
                'is_active'  => $data['is_active'] ?? true,
                'attributes' => $data['attributes'] ?? null,
                'meta'       => $data['meta'] ?? null,
            ]);

            if (!empty($data['attribute_values'])) {
                $this->syncVariantAttributeValues($variant, $data['attribute_values']);
            }

            return $variant->load(['attributeValues.attributeType']);
        });
    }

    /**
     * Update an existing variant's fields.
     *
     * Passing 'attribute_values' replaces the normalised pivot entries completely.
     */
    public function updateVariant(int $variantId, array $data): ProductVariant
    {
        $variant = ProductVariant::query()->findOrFail($variantId);

        return DB::transaction(function () use ($variant, $data): ProductVariant {
            $fillable = array_intersect_key($data, array_flip([
                'sku', 'name', 'barcode', 'weight_kg', 'sort_order', 'is_active', 'attributes', 'meta',
            ]));

            $variant->fill($fillable)->save();

            if (array_key_exists('attribute_values', $data)) {
                $this->syncVariantAttributeValues($variant, $data['attribute_values']);
            }

            return $variant->load(['attributeValues.attributeType']);
        });
    }

    /**
     * Soft-delete a variant.  Blocked if the variant has any committed
     * transaction lines (purchase/sale order items or stock movements).
     */
    public function deleteVariant(int $variantId): void
    {
        $variant = ProductVariant::query()->findOrFail($variantId);

        if ($variant->hasTransactionHistory()) {
            throw new \RuntimeException(
                "Variant [{$variantId}] cannot be deleted because it has transaction history. Deactivate it instead.",
            );
        }

        DB::transaction(function () use ($variant): void {
            $variant->warehouseProducts()->delete();
            $variant->prices()->delete();
            $variant->delete();
        });
    }

    /**
     * Deactivate a variant without deleting it (safe for variants with history).
     */
    public function deactivateVariant(int $variantId): ProductVariant
    {
        $variant = ProductVariant::query()->findOrFail($variantId);
        $variant->update(['is_active' => false]);

        return $variant;
    }

    /**
     * Duplicate an existing variant under the same (or different) product.
     * The duplicate always gets a new SKU; all other fields can be overridden.
     */
    public function duplicateVariant(int $variantId, array $overrides = []): ProductVariant
    {
        $source = ProductVariant::query()
            ->with('attributeValues')
            ->findOrFail($variantId);

        $data = array_merge([
            'sku'        => $source->sku . '-copy',
            'name'       => $source->name . ' (copy)',
            'barcode'    => null,
            'weight_kg'  => $source->weight_kg,
            'sort_order' => $source->sort_order,
            'is_active'  => false,
            'attributes' => $source->attributes,
            'meta'       => $source->meta,
        ], $overrides);

        $productId = (int) ($overrides['product_id'] ?? $source->product_id);

        return $this->createVariant($productId, $data);
    }

    // ── Attribute type / value management ────────────────────────────────────

    /**
     * Upsert an attribute type (e.g. Color, Size).
     */
    public function upsertAttributeType(string $slug, string $name, int $sortOrder = 0): ProductVariantAttributeType
    {
        return ProductVariantAttributeType::updateOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'sort_order' => $sortOrder],
        );
    }

    /**
     * Upsert an attribute value (e.g. Color → Red).
     */
    public function upsertAttributeValue(int $attributeTypeId, string $value, array $extra = []): ProductVariantAttributeValue
    {
        return ProductVariantAttributeValue::updateOrCreate(
            ['attribute_type_id' => $attributeTypeId, 'value' => $value],
            array_merge(['sort_order' => 0], $extra),
        );
    }

    /**
     * Sync the normalised pivot rows for a variant.
     *
     * $rows: [[attribute_type_id => int, attribute_value_id => int], ...]
     */
    private function syncVariantAttributeValues(ProductVariant $variant, array $rows): void
    {
        $sync = [];

        foreach ($rows as $row) {
            $sync[(int) $row['attribute_value_id']] = [
                'attribute_type_id' => (int) $row['attribute_type_id'],
            ];
        }

        $variant->attributeValues()->sync($sync);
    }

    /**
     * List product categories with active product count.
     */
    public function listProductCategories(bool $activeOnly = true): Collection
    {
        return ProductCategory::query()
            ->withCount(['products' => fn ($q) => $q->where('is_active', true)])
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Find a single product category with product count.
     */
    public function findProductCategory(int $id): ?ProductCategory
    {
        return ProductCategory::query()->withCount('products')->find($id);
    }

    /**
     * List customers with optional filters.
     *
     * Eager-loads 'media' — Customer's primary_image_url is an always-appended accessor
     * (HasPrimaryImage::getPrimaryImageUrlAttribute() -> getFirstMediaUrl()) that otherwise
     * lazy-loads the media relation once per customer, turning every call into an N+1.
     */
    public function listCustomers(bool $activeOnly = false, ?string $search = null): Collection
    {
        return Customer::query()
            ->with('media')
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->when($search, fn ($q) => $q->where(fn ($sq) => $sq->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
            ->orderBy('name')
            ->get();
    }

    /**
     * Find a single customer by ID.
     */
    public function findCustomer(int $id): ?Customer
    {
        return Customer::query()->find($id);
    }

    /**
     * Find the customer linked to a morphable model (e.g. a User).
     */
    public function findCustomerForModel(string $morphClass, int $morphId): ?Customer
    {
        return Customer::query()
            ->where('modelable_type', $morphClass)
            ->where('modelable_id', $morphId)
            ->first();
    }

    /**
     * Create a customer with auto-generated code.
     */
    public function createCustomer(array $data): Customer
    {
        if (empty($data['code'])) {
            $data['code'] = InventoryEntityRegistry::autoGeneratedCode('customers');
        }

        $data += [
            'credit_limit_amount' => 0,
            'is_active'           => true,
            'currency'            => config('inventory.base_currency', 'BDT'),
        ];

        return Customer::query()->create($data);
    }

    /**
     * Update a customer by ID.
     */
    public function updateCustomer(int $id, array $data): Customer
    {
        $customer = Customer::query()->findOrFail($id);
        $customer->update($data);

        return $customer->fresh();
    }

    /**
     * Delete a customer by ID.
     */
    public function deleteCustomer(int $id): void
    {
        Customer::query()->findOrFail($id)->delete();
    }

    /**
     * List sale orders with optional date/status filters.
     */
    public function listSaleOrders(
        ?string $status = null,
        ?string $from = null,
        ?string $to = null,
        bool $excludeTerminal = false,
    ): Collection {
        return SaleOrder::query()
            ->with(['customer', 'warehouse', 'items.product'])
            ->where('document_type', 'sale')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($excludeTerminal, fn ($q) => $q->whereNotIn('status', [SaleOrderStatus::FULFILLED->value, SaleOrderStatus::COMPLETED->value, SaleOrderStatus::CANCELLED->value, SaleOrderStatus::RETURNED->value]))
            ->when($from, fn ($q) => $q->where('ordered_at', '>=', DayRange::from($from)))
            ->when($to, fn ($q) => $q->where('ordered_at', '<', DayRange::until($to)))
            ->latest('ordered_at')
            ->get();
    }

    /**
     * Find a single sale order with relations.
     */
    public function findSaleOrder(int $id): ?SaleOrder
    {
        return SaleOrder::query()
            ->with(['customer', 'warehouse', 'items.product'])
            ->where('document_type', 'sale')
            ->find($id);
    }

    /**
     * Oldest pending sale order date (draft/confirmed/processing/partial).
     */
    public function oldestPendingOrderDate(): ?string
    {
        $order = SaleOrder::query()
            ->where('document_type', 'sale')
            ->whereIn('status', [
                SaleOrderStatus::DRAFT->value,
                SaleOrderStatus::CONFIRMED->value,
                SaleOrderStatus::PROCESSING->value,
                SaleOrderStatus::PARTIAL->value,
            ])
            ->oldest('ordered_at')
            ->first();

        return $order?->ordered_at?->toDateString();
    }

    /**
     * Estimate shipping cost for a list of items (product_id + qty).
     */
    public function estimateShipping(array $items): array
    {
        $products = Product::query()
            ->whereIn('id', collect($items)->pluck('product_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $rate = (float) config('inventory.shipping_rate_per_kg', env('ERP_APP_SHIPPING_RATE_PER_KG', 120));

        $totalWeightKg = collect($items)->sum(function (array $item) use ($products): float {
            $product = $products->get((int) ($item['product_id'] ?? 0));
            $qty = (float) ($item['qty'] ?? $item['quantity'] ?? 0);

            return (float) ($product?->weight_kg ?? 0) * $qty;
        });

        return [
            'total_weight_kg' => round($totalWeightKg, 4),
            'rate_per_kg'     => $rate,
            'shipping_cost'   => $totalWeightKg > 0 ? round($totalWeightKg * $rate, 2) : 0.0,
        ];
    }

    public function salesForecast(
        int $lookbackDays = 90,
        int $forecastDays = 90,
        int $productLimit = 12,
        int $customerLimit = 10,
    ): array {
        $lookbackDays = max(7, $lookbackDays);
        $forecastDays = max(7, $forecastDays);

        $historyEnd = now()->endOfDay();
        $historyStart = now()->copy()->subDays($lookbackDays - 1)->startOfDay();
        $observedDays = max(1, (int) floor($historyStart->diffInDays($historyEnd)) + 1);

        $saleOrdersQuery = SaleOrder::query()
            ->with(['customer', 'items.product'])
            ->where('document_type', 'order')
            ->whereIn('status', [
                SaleOrderStatus::CONFIRMED->value,
                SaleOrderStatus::PROCESSING->value,
                SaleOrderStatus::PARTIAL->value,
                SaleOrderStatus::FULFILLED->value,
                SaleOrderStatus::COMPLETED->value,
            ])
            ->whereBetween('ordered_at', [$historyStart, $historyEnd]);

        CommercialTeamAccess::applySalesScope($saleOrdersQuery);

        $saleOrders = $saleOrdersQuery->get();

        $items = $saleOrders->flatMap(function (SaleOrder $order): Collection {
            return $order->items->map(fn (SaleOrderItem $item): array => [
                'product_id'    => (int) $item->product_id,
                'product_name'  => $item->product?->name ?? ('#' . $item->product_id),
                'sku'           => $item->product?->sku ?? null,
                'customer_id'   => $order->customer_id ? (int) $order->customer_id : null,
                'customer_name' => $order->customer?->name ?? 'Walk-in',
                'zone'          => $order->customer?->zone ?: 'Unassigned',
                'area'          => $order->customer?->area ?: 'Unassigned',
                'demographic'   => $order->customer?->demographic_segment ?: 'Unassigned',
                'qty'           => (float) $item->qty_ordered,
                'fulfilled_qty' => (float) $item->qty_fulfilled,
                'revenue'       => (float) $item->line_total_local,
                'ordered_at'    => $order->ordered_at,
            ]);
        });

        $productIds = $items->pluck('product_id')
            ->filter()
            ->unique()
            ->values();

        $stockByProduct = WarehouseProduct::query()
            ->when($productIds->isNotEmpty(), fn ($query) => $query->whereIn('product_id', $productIds->all()))
            ->get()
            ->groupBy('product_id')
            ->map(function (Collection $rows): array {
                $qtyOnHand = (float) $rows->sum('qty_on_hand');
                $qtyReserved = (float) $rows->sum('qty_reserved');
                $qtyInTransit = (float) $rows->sum('qty_in_transit');
                $wacValue = (float) $rows->sum(fn (WarehouseProduct $row): float => (float) $row->qty_on_hand * (float) $row->wac_amount);

                return [
                    'qty_on_hand'    => round($qtyOnHand, 2),
                    'qty_reserved'   => round($qtyReserved, 2),
                    'qty_in_transit' => round($qtyInTransit, 2),
                    'qty_available'  => round($qtyOnHand - $qtyReserved, 2),
                    'wac_amount'     => $qtyOnHand > 0 ? round($wacValue / $qtyOnHand, 4) : 0.0,
                ];
            });

        $pendingSupplyByProduct = PurchaseOrderItem::query()
            ->with('purchaseOrder')
            ->when($productIds->isNotEmpty(), fn ($query) => $query->whereIn('product_id', $productIds->all()))
            ->get()
            ->filter(function (PurchaseOrderItem $item): bool {
                $status = $item->purchaseOrder?->status?->value;

                return in_array($status, [
                    PurchaseOrderStatus::DRAFT->value,
                    PurchaseOrderStatus::SUBMITTED->value,
                    PurchaseOrderStatus::CONFIRMED->value,
                    PurchaseOrderStatus::PARTIAL->value,
                ], true);
            })
            ->groupBy('product_id')
            ->map(function (Collection $rows): array {
                $pendingQty = (float) $rows->sum(fn (PurchaseOrderItem $row): float => $row->qtyPending());
                $pendingValue = (float) $rows->sum(fn (PurchaseOrderItem $row): float => $row->qtyPending() * (float) $row->unit_price_local);

                return [
                    'qty'      => round($pendingQty, 2),
                    'avg_cost' => $pendingQty > 0 ? round($pendingValue / $pendingQty, 4) : 0.0,
                ];
            });

        $customerCollectionRatio = $this->historicalCustomerCollectionRatio($historyStart, $historyEnd);
        $supplierPaymentRatio = $this->historicalSupplierPaymentRatio($historyStart, $historyEnd);

        $productForecast = $items
            ->groupBy('product_id')
            ->map(function (Collection $rows, int|string $productId) use ($observedDays, $forecastDays, $stockByProduct, $pendingSupplyByProduct): array {
                $productId = (int) $productId;
                $historyQty = (float) $rows->sum('qty');
                $fulfilledQty = (float) $rows->sum('fulfilled_qty');
                $historyRevenue = (float) $rows->sum('revenue');
                $activeDays = $rows->pluck('ordered_at')
                    ->filter()
                    ->map(fn ($orderedAt) => $orderedAt->toDateString())
                    ->unique()
                    ->count();

                $avgDailyQty = $historyQty > 0 ? $historyQty / $observedDays : 0.0;
                $avgDailyRevenue = $historyRevenue > 0 ? $historyRevenue / $observedDays : 0.0;
                $forecastQty = round($avgDailyQty * $forecastDays, 2);
                $forecastRevenue = round($avgDailyRevenue * $forecastDays, 2);
                $avgSellPrice = $historyQty > 0 ? round($historyRevenue / $historyQty, 4) : 0.0;

                $stock = $stockByProduct->get($productId, [
                    'qty_on_hand'    => 0.0,
                    'qty_reserved'   => 0.0,
                    'qty_in_transit' => 0.0,
                    'qty_available'  => 0.0,
                    'wac_amount'     => 0.0,
                ]);
                $pendingSupply = $pendingSupplyByProduct->get($productId, [
                    'qty'      => 0.0,
                    'avg_cost' => 0.0,
                ]);

                $availableSoon = (float) $stock['qty_available'] + (float) $stock['qty_in_transit'] + (float) $pendingSupply['qty'];
                $forecastGapQty = max(0.0, $forecastQty - $availableSoon);
                $daysOfCover = $avgDailyQty > 0 ? round(max(0.0, (float) $stock['qty_available']) / $avgDailyQty, 1) : null;
                $stockoutDate = $avgDailyQty > 0 && (float) $stock['qty_available'] > 0
                    ? now()->copy()->addDays((int) ceil((float) $stock['qty_available'] / $avgDailyQty))->toDateString()
                    : null;
                $procurementUnitCost = (float) ($pendingSupply['avg_cost'] > 0 ? $pendingSupply['avg_cost'] : $stock['wac_amount']);
                $forecastProcurementCost = round($forecastGapQty * $procurementUnitCost, 2);

                return [
                    'product_id'                => $productId,
                    'product_name'              => (string) $rows->first()['product_name'],
                    'sku'                       => $rows->first()['sku'],
                    'history_qty'               => round($historyQty, 2),
                    'fulfilled_qty'             => round($fulfilledQty, 2),
                    'history_revenue'           => round($historyRevenue, 2),
                    'avg_daily_qty'             => round($avgDailyQty, 4),
                    'avg_daily_revenue'         => round($avgDailyRevenue, 2),
                    'forecast_qty'              => $forecastQty,
                    'forecast_revenue'          => $forecastRevenue,
                    'avg_sell_price'            => $avgSellPrice,
                    'qty_available'             => round((float) $stock['qty_available'], 2),
                    'qty_in_transit'            => round((float) $stock['qty_in_transit'], 2),
                    'pending_supply_qty'        => round((float) $pendingSupply['qty'], 2),
                    'available_soon_qty'        => round($availableSoon, 2),
                    'forecast_gap_qty'          => round($forecastGapQty, 2),
                    'days_of_cover'             => $daysOfCover,
                    'stockout_date'             => $stockoutDate,
                    'active_days'               => $activeDays,
                    'confidence'                => round(min(100, ($activeDays / $observedDays) * 100), 1),
                    'forecast_procurement_cost' => $forecastProcurementCost,
                ];
            })
            ->sortByDesc('forecast_revenue')
            ->values();

        $customerForecast = $saleOrders
            ->groupBy(fn (SaleOrder $order) => $order->customer_id ?: 'walk-in')
            ->map(function (Collection $orders, int|string $customerKey) use ($observedDays, $forecastDays): array {
                $customerId = is_numeric($customerKey) ? (int) $customerKey : null;
                $ordersCount = $orders->count();
                $qty = (float) $orders->sum(fn (SaleOrder $order): float => (float) $order->items->sum('qty_ordered'));
                $revenue = (float) $orders->sum('total_local');
                $products = $orders->flatMap(fn (SaleOrder $order) => $order->items->pluck('product_id'))
                    ->filter()
                    ->unique()
                    ->count();
                $avgDailyQty = $qty > 0 ? $qty / $observedDays : 0.0;
                $avgDailyRevenue = $revenue > 0 ? $revenue / $observedDays : 0.0;

                return [
                    'customer_id'       => $customerId,
                    'customer_name'     => $customerId ? ($orders->first()?->customer?->name ?? 'Customer #' . $customerId) : 'Walk-in',
                    'zone'              => $orders->first()?->customer?->zone ?: 'Unassigned',
                    'area'              => $orders->first()?->customer?->area ?: 'Unassigned',
                    'demographic'       => $orders->first()?->customer?->demographic_segment ?: 'Unassigned',
                    'segment'           => $this->customerSegment($revenue, $ordersCount),
                    'orders_count'      => $ordersCount,
                    'products_count'    => $products,
                    'history_qty'       => round($qty, 2),
                    'history_revenue'   => round($revenue, 2),
                    'avg_daily_qty'     => round($avgDailyQty, 4),
                    'avg_daily_revenue' => round($avgDailyRevenue, 2),
                    'forecast_qty'      => round($avgDailyQty * $forecastDays, 2),
                    'forecast_revenue'  => round($avgDailyRevenue * $forecastDays, 2),
                ];
            })
            ->sortByDesc('forecast_revenue')
            ->values();

        $zoneForecast = $this->geographicCustomerForecast($saleOrders, $observedDays, $forecastDays, 'zone');
        $areaForecast = $this->geographicCustomerForecast($saleOrders, $observedDays, $forecastDays, 'area');
        $demographicForecast = $this->geographicCustomerForecast($saleOrders, $observedDays, $forecastDays, 'demographic_segment');

        $timeline = $this->buildForecastTimeline(
            $productForecast,
            $forecastDays,
            $customerCollectionRatio,
            $supplierPaymentRatio,
        );

        $holisticRequirement = [
            'products_tracked' => $productForecast->count(),
            'products_at_risk' => $productForecast
                ->filter(fn (array $product): bool => (float) $product['forecast_gap_qty'] > 0)
                ->count(),
            'forecast_qty'              => round((float) $productForecast->sum('forecast_qty'), 2),
            'forecast_revenue'          => round((float) $productForecast->sum('forecast_revenue'), 2),
            'required_qty'              => round((float) $productForecast->sum('forecast_gap_qty'), 2),
            'required_procurement_cost' => round((float) $productForecast->sum('forecast_procurement_cost'), 2),
            'collection_ratio'          => round($customerCollectionRatio, 2),
            'supplier_payment_ratio'    => round($supplierPaymentRatio, 2),
            'forecast_cash_in'          => round((float) $timeline['totals']['cash_in'], 2),
            'forecast_cash_out'         => round((float) $timeline['totals']['cash_out'], 2),
            'forecast_cash_net'         => round((float) $timeline['totals']['cash_net'], 2),
        ];

        return [
            'window' => [
                'history_start' => $historyStart->toDateString(),
                'history_end'   => $historyEnd->toDateString(),
                'lookback_days' => $lookbackDays,
                'forecast_days' => $forecastDays,
            ],
            'summary'      => $holisticRequirement,
            'products'     => $productForecast->take($productLimit)->values(),
            'customers'    => $customerForecast->take($customerLimit)->values(),
            'zones'        => $zoneForecast,
            'areas'        => $areaForecast,
            'demographics' => $demographicForecast,
            'timeline'     => $timeline,
        ];
    }

    public function salesTarget(
        int $lookbackDays = 90,
        int $targetDays = 30,
        ?float $expectedGrossMarginPct = null,
        float $desiredNetMarginPct = 10.0,
        float $growthPct = 0.0,
        ?float $expenseAllocationPct = null,
    ): array {
        return app(SalesTargetCalculator::class)->calculate(
            lookbackDays: $lookbackDays,
            targetDays: $targetDays,
            expectedGrossMarginPct: $expectedGrossMarginPct,
            desiredNetMarginPct: $desiredNetMarginPct,
            growthPct: $growthPct,
            expenseAllocationPct: $expenseAllocationPct,
        );
    }

    public function customerAnalytics(int $customerId, int $lookbackDays = 180, int $forecastDays = 90): array
    {
        $lookbackDays = max(7, $lookbackDays);
        $forecastDays = max(7, $forecastDays);
        $historyEnd = now()->endOfDay();
        $historyStart = now()->copy()->subDays($lookbackDays - 1)->startOfDay();
        $observedDays = max(1, (int) floor($historyStart->diffInDays($historyEnd)) + 1);

        // Lookback window — full details for trend + product analysis
        $ordersQuery = SaleOrder::query()
            ->with(['items.product', 'customer'])
            ->where('document_type', 'order')
            ->where('customer_id', $customerId)
            ->whereBetween('ordered_at', [$historyStart, $historyEnd])
            ->whereNotIn('status', [SaleOrderStatus::CANCELLED->value, SaleOrderStatus::RETURNED->value]);

        CommercialTeamAccess::applySalesScope($ordersQuery);

        $orders = $ordersQuery->get();
        $customer = $orders->first()?->customer ?? Customer::query()->find($customerId);
        $qty = (float) $orders->sum(fn (SaleOrder $o): float => (float) $o->items->sum('qty_ordered'));
        $revenue = (float) $orders->sum('total_local');

        $avgDailyRevenue = $revenue > 0 ? $revenue / $observedDays : 0.0;
        $avgDailyQty = $qty > 0 ? $qty / $observedDays : 0.0;
        $lastOrder = $orders->sortByDesc(fn (SaleOrder $o) => $o->ordered_at?->getTimestamp() ?? 0)->first();
        $daysSince = $lastOrder?->ordered_at ? (int) $lastOrder->ordered_at->diffInDays(now()) : null;
        $avgOrderValue = $orders->count() > 0 ? round($revenue / $orders->count(), 2) : 0.0;

        // All-time — lightweight query (order-level only) for CLV and RFM
        $allTimeQuery = SaleOrder::query()
            ->where('document_type', 'order')
            ->where('customer_id', $customerId)
            ->whereNotIn('status', [SaleOrderStatus::CANCELLED->value, SaleOrderStatus::RETURNED->value])
            ->orderBy('ordered_at');

        CommercialTeamAccess::applySalesScope($allTimeQuery);

        $allTimeOrders = $allTimeQuery->get(['id', 'ordered_at', 'total_local']);
        $allTimeCount = $allTimeOrders->count();
        $allTimeRevenue = (float) $allTimeOrders->sum('total_local');
        $firstOrderAt = $allTimeOrders->first()?->ordered_at;
        $customerAgeDays = $firstOrderAt ? max(1, (int) $firstOrderAt->diffInDays(now())) : 1;

        // Purchase frequency
        $ordersPerMonth = ($orders->count() / max(1, $observedDays)) * 30;
        $ordersPerYear = $ordersPerMonth * 12;

        // Average purchase interval (all-time)
        $avgPurchaseInterval = $allTimeCount > 1
            ? (int) round($customerAgeDays / ($allTimeCount - 1))
            : null;

        // CLV — avg order value × annual frequency × projected lifespan
        $segment = $this->customerSegment($revenue, $orders->count());
        $lifespanYears = match ($segment) {
            'Strategic' => 5.0,
            'Growth'    => 3.0,
            'Repeat'    => 2.0,
            default     => 1.0,
        };
        $clvSimple = round($avgOrderValue * $ordersPerYear * $lifespanYears, 2);

        // Churn risk
        $churnRisk = 'none';

        if ($daysSince !== null) {
            if ($avgPurchaseInterval !== null && $avgPurchaseInterval > 0) {
                $ratio = $daysSince / $avgPurchaseInterval;
                $churnRisk = match (true) {
                    $ratio >= 3.0 => 'high',
                    $ratio >= 2.0 => 'medium',
                    $ratio >= 1.5 => 'low',
                    default       => 'none',
                };
            } else {
                $churnRisk = match (true) {
                    $daysSince > 180 => 'high',
                    $daysSince > 90  => 'medium',
                    $daysSince > 45  => 'low',
                    default          => 'none',
                };
            }
        }

        // RFM scores (1–5)
        $rfmRecency = match (true) {
            $daysSince === null => 1,
            $daysSince <= 7     => 5,
            $daysSince <= 30    => 4,
            $daysSince <= 90    => 3,
            $daysSince <= 180   => 2,
            default             => 1,
        };
        $rfmFrequency = match (true) {
            $allTimeCount >= 24 => 5,
            $allTimeCount >= 12 => 4,
            $allTimeCount >= 6  => 3,
            $allTimeCount >= 2  => 2,
            default             => 1,
        };
        $rfmMonetary = match (true) {
            $allTimeRevenue >= 500000 => 5,
            $allTimeRevenue >= 100000 => 4,
            $allTimeRevenue >= 20000  => 3,
            $allTimeRevenue >= 5000   => 2,
            default                   => 1,
        };
        $rfmAvg = ($rfmRecency + $rfmFrequency + $rfmMonetary) / 3.0;
        $rfmLabel = match (true) {
            $rfmRecency >= 4 && $rfmFrequency >= 4 && $rfmMonetary >= 4 => 'VIP',
            $rfmAvg >= 4.0                                              => 'Loyal',
            $rfmRecency <= 2 && $rfmFrequency >= 3 && $rfmMonetary >= 4 => 'Cannot Lose',
            $rfmRecency <= 2 && $rfmAvg >= 3.0                          => 'At Risk',
            $rfmRecency <= 2                                            => 'Lost',
            $rfmRecency >= 4 && $rfmFrequency <= 2                      => 'Promising',
            $rfmFrequency >= 3                                          => 'Potential Loyal',
            default                                                     => 'Active',
        };

        // Monthly trend (lookback window, ascending)
        $monthlyTrend = $orders
            ->groupBy(fn (SaleOrder $o): string => $o->ordered_at?->format('Y-m') ?? 'unknown')
            ->map(fn (Collection $monthOrders, string $key): array => [
                'month'        => Carbon::createFromFormat('Y-m', $key)?->format('M y') ?? $key,
                'revenue'      => round((float) $monthOrders->sum('total_local'), 2),
                'orders_count' => $monthOrders->count(),
                'qty'          => round((float) $monthOrders->sum(fn (SaleOrder $o): float => (float) $o->items->sum('qty_ordered')), 2),
            ])
            ->sortKeys()
            ->values()
            ->all();

        // Top 5 products by revenue (lookback window)
        $topProducts = $orders
            ->flatMap(fn (SaleOrder $o) => $o->items)
            ->groupBy('product_id')
            ->map(fn (Collection $items, mixed $productId): array => [
                'product_id'   => $productId,
                'name'         => $items->first()?->product?->name ?? 'Unknown',
                'qty'          => round((float) $items->sum('qty_ordered'), 2),
                'revenue'      => round((float) $items->sum('line_total_local'), 2),
                'orders_count' => $items->pluck('sale_order_id')->unique()->count(),
            ])
            ->sortByDesc('revenue')
            ->values()
            ->take(5)
            ->all();

        return [
            // Profile
            'segment'          => $segment,
            'zone'             => $customer?->zone ?: 'Unassigned',
            'area'             => $customer?->area ?: 'Unassigned',
            'demographic'      => $customer?->demographic_segment ?: 'Unassigned',
            'demographic_data' => $customer?->demographic_data ?: [],

            // Lookback window
            'orders_count'      => $orders->count(),
            'history_qty'       => round($qty, 2),
            'history_revenue'   => round($revenue, 2),
            'avg_order_value'   => $avgOrderValue,
            'forecast_qty'      => round($avgDailyQty * $forecastDays, 2),
            'forecast_revenue'  => round($avgDailyRevenue * $forecastDays, 2),
            'last_order_at'     => $lastOrder?->ordered_at?->toDateString(),
            'days_since_order'  => $daysSince,
            'distinct_products' => $orders->flatMap(fn (SaleOrder $o) => $o->items->pluck('product_id'))->filter()->unique()->count(),
            'orders_per_month'  => round($ordersPerMonth, 2),
            'forecast_days'     => $forecastDays,
            'lookback_days'     => $lookbackDays,

            // All-time
            'first_order_at'    => $firstOrderAt?->toDateString(),
            'customer_age_days' => $customerAgeDays,
            'all_time_orders'   => $allTimeCount,
            'all_time_revenue'  => round($allTimeRevenue, 2),

            // CLV
            'clv_simple'            => $clvSimple,
            'clv_lifespan_years'    => $lifespanYears,
            'purchase_frequency'    => round($ordersPerYear, 1),
            'avg_purchase_interval' => $avgPurchaseInterval,

            // RFM
            'rfm_recency'   => $rfmRecency,
            'rfm_frequency' => $rfmFrequency,
            'rfm_monetary'  => $rfmMonetary,
            'rfm_label'     => $rfmLabel,

            // Churn
            'churn_risk' => $churnRisk,

            // Trend + Products
            'monthly_trend' => $monthlyTrend,
            'top_products'  => $topProducts,
        ];
    }

    public function customerSalesHeatmap(
        string $startDate = '',
        string $endDate = '',
        string $metric = 'revenue',
    ): array {
        $sortFn = static fn (string $a, string $b): int => $a === 'Unassigned' ? 1 : ($b === 'Unassigned' ? -1 : strcmp($a, $b));

        $query = SaleOrder::query()
            ->with('customer:id,geo')
            ->where('document_type', 'order')
            ->whereNotIn('status', [SaleOrderStatus::CANCELLED->value, SaleOrderStatus::RETURNED->value]);

        if ($startDate !== '') {
            $query->where('ordered_at', '>=', DayRange::from($startDate));
        }

        if ($endDate !== '') {
            $query->where('ordered_at', '<', DayRange::until($endDate));
        }

        CommercialTeamAccess::applySalesScope($query);

        $orders = $query->get(['id', 'customer_id', 'total_local', 'status', 'ordered_at']);

        // Accumulate into zone × area buckets
        $raw = [];

        foreach ($orders as $order) {
            $zone = trim((string) ($order->customer?->zone ?: '')) ?: 'Unassigned';
            $area = trim((string) ($order->customer?->area ?: '')) ?: 'Unassigned';

            if (!isset($raw[$zone][$area])) {
                $raw[$zone][$area] = ['revenue' => 0.0, 'orders' => 0, 'customer_ids' => []];
            }

            $raw[$zone][$area]['revenue'] += (float) $order->total_local;
            $raw[$zone][$area]['orders']++;

            if ($order->customer_id !== null) {
                $raw[$zone][$area]['customer_ids'][(int) $order->customer_id] = true;
            }
        }

        // Collect and sort zones and areas — 'Unassigned' always last
        $zones = array_keys($raw);
        usort($zones, $sortFn);

        $allAreas = [];

        foreach ($raw as $areaMap) {
            foreach (array_keys($areaMap) as $a) {
                $allAreas[$a] = true;
            }
        }
        $areas = array_keys($allAreas);
        usort($areas, $sortFn);

        // Build normalised cell matrix
        $cells = [];

        foreach ($zones as $zone) {
            foreach ($areas as $area) {
                $bucket = $raw[$zone][$area] ?? null;
                $revenue = $bucket ? round($bucket['revenue'], 2) : 0.0;
                $oCount = $bucket ? $bucket['orders'] : 0;
                $cCount = $bucket ? count($bucket['customer_ids']) : 0;
                $avgOrder = $oCount > 0 ? round($revenue / $oCount, 2) : 0.0;

                $cells[$zone][$area] = [
                    'revenue'   => $revenue,
                    'orders'    => $oCount,
                    'customers' => $cCount,
                    'avg_order' => $avgOrder,
                ];
            }
        }

        // Max cell value for the chosen metric (used for color intensity)
        $maxValue = 0.0;

        foreach ($zones as $zone) {
            foreach ($areas as $area) {
                $val = match ($metric) {
                    'orders'    => (float) $cells[$zone][$area]['orders'],
                    'customers' => (float) $cells[$zone][$area]['customers'],
                    'avg_order' => $cells[$zone][$area]['avg_order'],
                    default     => $cells[$zone][$area]['revenue'],
                };

                if ($val > $maxValue) {
                    $maxValue = $val;
                }
            }
        }

        // Zone totals (proper union of customer_ids)
        $zoneTotals = [];

        foreach ($zones as $zone) {
            $zoneCustomers = [];
            $zoneRevenue = 0.0;
            $zoneOrders = 0;

            foreach ($areas as $area) {
                $bucket = $raw[$zone][$area] ?? null;

                if ($bucket === null) {
                    continue;
                }
                $zoneRevenue += $bucket['revenue'];
                $zoneOrders += $bucket['orders'];

                foreach (array_keys($bucket['customer_ids']) as $cid) {
                    $zoneCustomers[$cid] = true;
                }
            }

            $zoneTotals[$zone] = [
                'revenue'   => round($zoneRevenue, 2),
                'orders'    => $zoneOrders,
                'customers' => count($zoneCustomers),
            ];
        }

        // Area totals (proper union of customer_ids)
        $areaTotals = [];

        foreach ($areas as $area) {
            $areaCustomers = [];
            $areaRevenue = 0.0;
            $areaOrders = 0;

            foreach ($zones as $zone) {
                $bucket = $raw[$zone][$area] ?? null;

                if ($bucket === null) {
                    continue;
                }
                $areaRevenue += $bucket['revenue'];
                $areaOrders += $bucket['orders'];

                foreach (array_keys($bucket['customer_ids']) as $cid) {
                    $areaCustomers[$cid] = true;
                }
            }

            $areaTotals[$area] = [
                'revenue'   => round($areaRevenue, 2),
                'orders'    => $areaOrders,
                'customers' => count($areaCustomers),
            ];
        }

        // Grand total
        $grandCustomers = [];

        foreach ($raw as $areaMap) {
            foreach ($areaMap as $bucket) {
                foreach (array_keys($bucket['customer_ids']) as $cid) {
                    $grandCustomers[$cid] = true;
                }
            }
        }

        return [
            'zones'       => $zones,
            'areas'       => $areas,
            'cells'       => $cells,
            'zone_totals' => $zoneTotals,
            'area_totals' => $areaTotals,
            'max_value'   => $maxValue,
            'grand_total' => [
                'revenue'   => round((float) array_sum(array_column($zoneTotals, 'revenue')), 2),
                'orders'    => (int) array_sum(array_column($zoneTotals, 'orders')),
                'customers' => count($grandCustomers),
            ],
            'metric' => $metric,
            'period' => ['start' => $startDate, 'end' => $endDate],
        ];
    }

    private function geographicCustomerForecast(Collection $saleOrders, int $observedDays, int $forecastDays, string $field): Collection
    {
        return $saleOrders
            ->groupBy(fn (SaleOrder $order): string => (string) ($order->customer?->{$field} ?: 'Unassigned'))
            ->map(function (Collection $orders, string $name) use ($observedDays, $forecastDays, $field): array {
                $ordersCount = $orders->count();
                $qty = (float) $orders->sum(fn (SaleOrder $order): float => (float) $order->items->sum('qty_ordered'));
                $revenue = (float) $orders->sum('total_local');
                $customerCount = $orders->pluck('customer_id')->filter()->unique()->count();
                $avgDailyQty = $qty > 0 ? $qty / $observedDays : 0.0;
                $avgDailyRevenue = $revenue > 0 ? $revenue / $observedDays : 0.0;

                return [
                    $field             => $name,
                    'segment'          => $this->customerSegment($revenue, $ordersCount),
                    'customers_count'  => $customerCount,
                    'orders_count'     => $ordersCount,
                    'history_qty'      => round($qty, 2),
                    'history_revenue'  => round($revenue, 2),
                    'forecast_qty'     => round($avgDailyQty * $forecastDays, 2),
                    'forecast_revenue' => round($avgDailyRevenue * $forecastDays, 2),
                ];
            })
            ->sortByDesc('forecast_revenue')
            ->values();
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

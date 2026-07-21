<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Models\{Customer, Product, ProductVariant, SaleOrder, SaleOrderItem};
use Centrex\Inventory\Support\CommercialTeamAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Gate, Route};
use Livewire\Attributes\{Layout, Url};
use Livewire\Component;

#[Layout('layouts.app')]
class SalesReportPage extends Component
{
    public string $startDate = '';

    public string $endDate = '';

    #[Url(as: 'customer', except: null)]
    public ?int $customerId = null;

    #[Url(as: 'product', except: null)]
    public ?int $productId = null;

    public ?int $viewingOrderId = null;

    public function mount(): void
    {
        Gate::authorize('inventory.reports.view');
        $this->endDate = now()->toDateString();
        $this->startDate = now()->startOfMonth()->toDateString();
    }

    public function viewOrder(int $id): void
    {
        $this->viewingOrderId = $id;
        $this->dispatch('open-modal', 'sale-order-detail');
    }

    public function closeOrderModal(): void
    {
        $this->viewingOrderId = null;
        $this->dispatch('close-modal', 'sale-order-detail');
    }

    public function render(): View
    {
        $recentOrders = $this->scopedSalesQuery()
            ->with(['customer', 'warehouse'])
            ->latest('ordered_at')
            ->latest('id')
            ->limit(25)
            ->get();

        $selectedCustomer = $this->customerId ? Customer::query()->find($this->customerId) : null;
        $selectedProduct = $this->productId ? Product::query()->find($this->productId) : null;

        // Computed once and threaded through — distinctProductCount(), buildSoldProductsReport(),
        // and invoiceSummary() (via buildSalesMetrics()) all used to independently re-run this
        // same scoped-order-ids query on every render.
        $orderIds = $this->scopedOrderIds();

        return view('inventory::livewire.transactions.sales-report', [
            'saleOrders'   => $recentOrders,
            'salesMetrics' => $this->buildSalesMetrics($orderIds),
            'productCount' => $this->distinctProductCount($orderIds),
            'soldProducts' => $this->buildSoldProductsReport($orderIds),
            'viewingOrder' => $this->viewingOrderId
                ? SaleOrder::query()->with(['customer', 'warehouse', 'items.product', 'items.variant'])->find($this->viewingOrderId)
                : null,
            'customerLedgerUrl'       => $this->customerLedgerUrl($selectedCustomer),
            'selectedCustomerOptions' => $selectedCustomer
                ? [
                    $selectedCustomer->id => [
                        'label'    => (string) ($selectedCustomer->organization_name ?: $selectedCustomer->name),
                        'sublabel' => filled($selectedCustomer->phone) ? (string) $selectedCustomer->phone : null,
                    ],
                ]
                : [],
            'selectedProductOptions' => $selectedProduct
                ? [
                    $selectedProduct->id => [
                        'label'    => (string) $selectedProduct->name,
                        'sublabel' => filled($selectedProduct->sku) ? (string) $selectedProduct->sku : null,
                    ],
                ]
                : [],
        ]);
    }

    /** Link to the customer's accounting ledger, when laravel-accounting is installed and the two records are linked. */
    private function customerLedgerUrl(?Customer $customer): ?string
    {
        if (!$customer || !$customer->accounting_customer_id || !class_exists(\Centrex\Accounting\Models\Customer::class) || !Route::has('accounting.customers.ledger')) {
            return null;
        }

        return route('accounting.customers.ledger', ['customer' => $customer->accounting_customer_id]);
    }

    /**
     * Base query scoped to the selected date range, customer, and the current user's
     * commercial-team visibility — every metric below is computed from this, not from the
     * capped "recent orders" list, so KPI totals reflect the full period rather than just
     * the rows currently shown on screen.
     */
    private function scopedSalesQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = SaleOrder::query()
            ->where('document_type', 'order')
            ->when($this->startDate !== '', fn ($query) => $query->whereDate('ordered_at', '>=', $this->startDate))
            ->when($this->endDate !== '', fn ($query) => $query->whereDate('ordered_at', '<=', $this->endDate))
            ->when($this->customerId, fn ($query) => $query->where('customer_id', $this->customerId))
            ->when($this->productId, fn ($query) => $query->whereHas('items', fn ($itemQuery) => $itemQuery->where('product_id', $this->productId)));

        CommercialTeamAccess::applySalesScope($query);

        return $query;
    }

    /**
     * Distinct products sold in the selected date range — counted separately from the
     * top-50 breakdown table below so the KPI reflects the true total, not just the top rows shown.
     */
    private function distinctProductCount(?Collection $orderIds = null): int
    {
        $orderIds ??= $this->scopedOrderIds();

        if ($orderIds->isEmpty()) {
            return 0;
        }

        return (int) SaleOrderItem::query()
            ->whereIn('sale_order_id', $orderIds)
            ->when($this->productId, fn ($query) => $query->where('product_id', $this->productId))
            ->distinct()
            ->count('product_id');
    }

    /**
     * Units sold per product/variant in the selected date range, ranked by quantity.
     * Draft and cancelled orders don't count as "sold". Scoped to the current user's
     * commercial-team visibility, same as the sale orders list above.
     */
    private function buildSoldProductsReport(?Collection $orderIds = null): Collection
    {
        $orderIds ??= $this->scopedOrderIds();

        if ($orderIds->isEmpty()) {
            return collect();
        }

        $rows = SaleOrderItem::query()
            ->whereIn('sale_order_id', $orderIds)
            ->when($this->productId, fn ($query) => $query->where('product_id', $this->productId))
            ->selectRaw('product_id, variant_id, SUM(qty_ordered) as qty_sold, SUM(line_total_local) as revenue_local, COUNT(DISTINCT sale_order_id) as orders_count')
            ->groupBy('product_id', 'variant_id')
            ->orderByDesc('qty_sold')
            ->limit(50)
            ->get();

        $productIds = $rows->pluck('product_id')->filter()->unique();
        $variantIds = $rows->pluck('variant_id')->filter()->unique();

        $products = $productIds->isEmpty()
            ? collect()
            : Product::query()->whereIn('id', $productIds)->get()->keyBy('id');
        $variants = $variantIds->isEmpty()
            ? collect()
            : ProductVariant::query()->whereIn('id', $variantIds)->get()->keyBy('id');

        return $rows->map(function (SaleOrderItem $row) use ($products, $variants): array {
            $product = $products->get((int) $row->product_id);
            $variant = $row->variant_id ? $variants->get((int) $row->variant_id) : null;

            return [
                'product_id'    => $row->product_id,
                'variant_id'    => $row->variant_id,
                'name'          => $product?->name ?? $variant?->display_name ?? ('Product #' . $row->product_id),
                'sku'           => $variant?->sku ?: $product?->sku,
                'qty_sold'      => round((float) $row->qty_sold, 2),
                'revenue_local' => round((float) $row->revenue_local, 2),
                'orders_count'  => (int) $row->orders_count,
            ];
        })->values();
    }

    private function scopedOrderIds(): Collection
    {
        return $this->scopedSalesQuery()
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->pluck('id');
    }

    /**
     * Aggregated over the full scoped date range — not the capped "recent orders" list — via
     * SQL SUM()/COUNT() rather than pulling every matching row into a Collection, since a full
     * quarter's worth of orders can be thousands of rows just to produce a handful of totals.
     * Draft and cancelled orders are excluded from every monetary figure, matching the
     * "Sold Products" table, so a cancelled order can't inflate revenue/order-count KPIs —
     * $orderIds (from scopedOrderIds()) already excludes them. `status_counts` still reflects
     * every status (including cancelled/draft) so the breakdown remains informative.
     */
    private function buildSalesMetrics(?Collection $orderIds = null): array
    {
        $orderIds ??= $this->scopedOrderIds();
        $invoiceSummary = $this->invoiceSummary($orderIds);

        $totals = $orderIds->isEmpty()
            ? null
            : SaleOrder::query()
                ->whereIn('id', $orderIds)
                ->toBase()
                ->selectRaw("
                    COUNT(*) as cnt,
                    SUM(subtotal_local) as gross_subtotal,
                    SUM(discount_local) as discount,
                    SUM(tax_local) as tax,
                    SUM(shipping_local) as shipping,
                    SUM(total_local) as net_total,
                    SUM(CASE WHEN status IN ('fulfilled', 'partial') THEN total_local ELSE 0 END) as fulfilled_total
                ")
                ->first();

        $statusCounts = $this->scopedSalesQuery()
            ->toBase()
            ->select('status')
            ->selectRaw('COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->mapWithKeys(fn ($cnt, $status) => [($status ?: 'unknown') => (int) $cnt])
            ->all();

        return [
            'count'           => (int) ($totals->cnt ?? 0),
            'gross_subtotal'  => round((float) ($totals->gross_subtotal ?? 0), 2),
            'discount'        => round((float) ($totals->discount ?? 0), 2),
            'tax'             => round((float) ($totals->tax ?? 0), 2),
            'shipping'        => round((float) ($totals->shipping ?? 0), 2),
            'net_total'       => round((float) ($totals->net_total ?? 0), 2),
            'fulfilled_total' => round((float) ($totals->fulfilled_total ?? 0), 2),
            'invoice_paid'    => $invoiceSummary['paid'],
            'invoice_due'     => $invoiceSummary['due'],
            'status_counts'   => $statusCounts,
        ];
    }

    /**
     * Invoice paid/due totals from laravel-accounting, scoped to exactly the sale orders
     * matched by the current date range, customer, product, and commercial-team filters —
     * not every invoice in the date range regardless of who/what it's for.
     *
     * `base_paid_amount`/`base_balance` are Invoice accessors (currency conversion, plus
     * `balance` folding in AR-reducing expenses and posted credit memos via their own
     * sub-queries — see Invoice::getBalanceAttribute()) rather than plain columns, so this
     * can't be pushed into a single SQL SUM() without reimplementing that business logic here.
     */
    private function invoiceSummary(Collection $orderIds): array
    {
        $invoiceClass = \Centrex\Accounting\Models\Invoice::class;

        if (!class_exists($invoiceClass) || $orderIds->isEmpty()) {
            return ['paid' => 0.0, 'due' => 0.0];
        }

        $invoices = $invoiceClass::query()
            ->where(function ($query) use ($orderIds): void {
                $query->where(fn ($q) => $q->where('source_type', SaleOrder::class)->whereIn('source_id', $orderIds))
                    ->orWhereIn('inventory_sale_order_id', $orderIds);
            })
            ->get();

        return [
            'paid' => round((float) $invoices->sum('base_paid_amount'), 2),
            'due'  => round((float) $invoices->sum('base_balance'), 2),
        ];
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Models\{Product, ProductVariant, PurchaseOrder, PurchaseOrderItem, Supplier};
use Centrex\Inventory\Support\CommercialTeamAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Gate, Route};
use Livewire\Attributes\{Layout, Url};
use Livewire\Component;

#[Layout('layouts.app')]
class PurchaseReportPage extends Component
{
    public string $startDate = '';

    public string $endDate = '';

    #[Url(as: 'supplier', except: null)]
    public ?int $supplierId = null;

    public ?int $viewingOrderId = null;

    public function mount(): void
    {
        Gate::authorize('inventory.reports.view');
        $this->endDate = now()->toDateString();
        $this->startDate = now()->subDays(29)->toDateString();
    }

    public function viewOrder(int $id): void
    {
        $this->viewingOrderId = $id;
        $this->dispatch('open-modal', 'purchase-order-detail');
    }

    public function closeOrderModal(): void
    {
        $this->viewingOrderId = null;
        $this->dispatch('close-modal', 'purchase-order-detail');
    }

    public function render(): View
    {
        $recentOrders = $this->scopedPurchaseQuery()
            ->with(['supplier', 'warehouse'])
            ->latest('ordered_at')
            ->latest('id')
            ->limit(25)
            ->get();

        $selectedSupplier = $this->supplierId ? Supplier::query()->find($this->supplierId) : null;

        return view('inventory::livewire.transactions.purchase-report', [
            'purchaseOrders'    => $recentOrders,
            'purchaseMetrics'   => $this->buildPurchaseMetrics(),
            'productCount'      => $this->distinctProductCount(),
            'purchasedProducts' => $this->buildPurchasedProductsReport(),
            'viewingOrder'      => $this->viewingOrderId
                ? PurchaseOrder::query()->with(['supplier', 'warehouse', 'items.product', 'items.variant'])->find($this->viewingOrderId)
                : null,
            'supplierLedgerUrl'       => $this->supplierLedgerUrl($selectedSupplier),
            'selectedSupplierOptions' => $selectedSupplier
                ? [$selectedSupplier->id => ['label' => (string) $selectedSupplier->name]]
                : [],
        ]);
    }

    /** Link to the supplier's accounting ledger, when laravel-accounting is installed and the two records are linked. */
    private function supplierLedgerUrl(?Supplier $supplier): ?string
    {
        if (!$supplier || !$supplier->accounting_vendor_id || !class_exists(\Centrex\Accounting\Models\Vendor::class) || !Route::has('accounting.vendors.ledger')) {
            return null;
        }

        return route('accounting.vendors.ledger', ['vendor' => $supplier->accounting_vendor_id]);
    }

    /**
     * Base query scoped to the selected date range, supplier, and the current user's
     * commercial-team visibility — every metric below is computed from this, not from the
     * capped "recent orders" list, so KPI totals reflect the full period rather than just
     * the rows currently shown on screen.
     */
    private function scopedPurchaseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = PurchaseOrder::query()
            ->where('document_type', 'order')
            ->when($this->startDate !== '', fn ($query) => $query->whereDate('ordered_at', '>=', $this->startDate))
            ->when($this->endDate !== '', fn ($query) => $query->whereDate('ordered_at', '<=', $this->endDate))
            ->when($this->supplierId, fn ($query) => $query->where('supplier_id', $this->supplierId));

        CommercialTeamAccess::applyPurchaseScope($query);

        return $query;
    }

    /**
     * Distinct products purchased in the selected date range — counted separately from the
     * top-50 breakdown table below so the KPI reflects the true total, not just the top rows shown.
     */
    private function distinctProductCount(): int
    {
        $orderIds = $this->scopedOrderIds();

        if ($orderIds->isEmpty()) {
            return 0;
        }

        return (int) PurchaseOrderItem::query()
            ->whereIn('purchase_order_id', $orderIds)
            ->distinct()
            ->count('product_id');
    }

    /**
     * Units purchased per product/variant in the selected date range, ranked by quantity.
     * Draft and cancelled orders don't count as "purchased". Scoped to the current user's
     * commercial-team visibility, same as the purchase orders list above.
     */
    private function buildPurchasedProductsReport(): Collection
    {
        $orderIds = $this->scopedOrderIds();

        if ($orderIds->isEmpty()) {
            return collect();
        }

        $rows = PurchaseOrderItem::query()
            ->whereIn('purchase_order_id', $orderIds)
            ->selectRaw('product_id, variant_id, SUM(qty_ordered) as qty_purchased, SUM(line_total_local) as cost_local, COUNT(DISTINCT purchase_order_id) as orders_count')
            ->groupBy('product_id', 'variant_id')
            ->orderByDesc('qty_purchased')
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

        return $rows->map(function (PurchaseOrderItem $row) use ($products, $variants): array {
            $product = $products->get((int) $row->product_id);
            $variant = $row->variant_id ? $variants->get((int) $row->variant_id) : null;

            return [
                'product_id'    => $row->product_id,
                'variant_id'    => $row->variant_id,
                'name'          => $variant?->display_name ?? $product?->name ?? ('Product #' . $row->product_id),
                'sku'           => $variant?->sku ?: $product?->sku,
                'qty_purchased' => round((float) $row->qty_purchased, 2),
                'cost_local'    => round((float) $row->cost_local, 2),
                'orders_count'  => (int) $row->orders_count,
            ];
        })->values();
    }

    private function scopedOrderIds(): Collection
    {
        return $this->scopedPurchaseQuery()
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->pluck('id');
    }

    /** Aggregated over the full scoped date range — not the capped "recent orders" list. */
    private function buildPurchaseMetrics(): array
    {
        $billSummary = $this->billSummary();

        $orders = $this->scopedPurchaseQuery()
            ->get(['id', 'status', 'subtotal_local', 'tax_local', 'shipping_local', 'other_charges_amount', 'total_local']);

        return [
            'count'          => $orders->count(),
            'gross_subtotal' => round((float) $orders->sum('subtotal_local'), 2),
            'tax'            => round((float) $orders->sum('tax_local'), 2),
            'shipping'       => round((float) $orders->sum('shipping_local'), 2),
            'other_charges'  => round((float) $orders->sum('other_charges_amount'), 2),
            'net_total'      => round((float) $orders->sum('total_local'), 2),
            'received_total' => round((float) $orders->filter(fn (PurchaseOrder $order) => in_array($order->status?->value, ['received', 'partial'], true))->sum('total_local'), 2),
            'bill_paid'      => $billSummary['paid'],
            'bill_due'       => $billSummary['due'],
            'status_counts'  => $orders->groupBy(fn (PurchaseOrder $order) => $order->status?->value ?? 'unknown')
                ->map(fn (Collection $group) => $group->count())
                ->all(),
        ];
    }

    /** Bill paid/due totals from laravel-accounting for purchase orders in the selected period. */
    private function billSummary(): array
    {
        $billClass = \Centrex\Accounting\Models\Bill::class;

        if (!class_exists($billClass)) {
            return ['paid' => 0.0, 'due' => 0.0];
        }

        $bills = $billClass::query()
            ->where(function ($query): void {
                $query->where('source_type', PurchaseOrder::class)
                    ->orWhereNotNull('inventory_purchase_order_id');
            })
            ->when($this->startDate !== '', fn ($query) => $query->whereDate('bill_date', '>=', $this->startDate))
            ->when($this->endDate !== '', fn ($query) => $query->whereDate('bill_date', '<=', $this->endDate))
            ->get();

        return [
            'paid' => round((float) $bills->sum('base_paid_amount'), 2),
            'due'  => round((float) $bills->sum('base_balance'), 2),
        ];
    }
}

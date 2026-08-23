<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Http\Livewire\Transactions\Concerns\ScopesSalesReport;
use Centrex\Inventory\Models\{Product, ProductVariant, SaleOrderItem};
use Centrex\TallUi\Concerns\CachesData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Blade, Gate};
use Livewire\Component;

/**
 * Split out of SalesReportPage's "Sold Products" tab, scoped to the same date/customer/
 * product filters as the Sale Statistics and Recent Sales tabs (see ScopesSalesReport).
 *
 * Adds cost/profit/margin columns on top of the original qty/revenue/orders breakdown —
 * cost and profit are computed only over the fulfilled quantity (qty_fulfilled *
 * unit_cost_amount / unit_price_amount), same "only what's actually been costed" rule
 * SalesOrderProfitSummary applies at the order level: unit_cost_amount isn't populated
 * until fulfillSaleOrder() runs, so blending it against the *full ordered* revenue would
 * overstate margin on partially/un-fulfilled lines. `revenue_local` (the original column)
 * still reflects every ordered unit; profit/margin are deliberately a narrower, fulfilled-
 * only figure and won't reconcile against it — that's intentional, not a bug.
 */
class InventorySoldProductsCard extends Component
{
    use CachesData;
    use ScopesSalesReport;

    public function mount(string $startDate = '', string $endDate = '', ?int $customerId = null, ?int $productId = null, ?int $employeeId = null): void
    {
        Gate::authorize('inventory.reports.view');

        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->customerId = $customerId;
        $this->productId = $productId;
        $this->employeeId = $employeeId;
        $this->cacheTtl = 120;
    }

    public function placeholder(): string
    {
        return Blade::render(<<<'BLADE'
            <div role="status" aria-label="Loading sold products" class="animate-pulse">
                <div class="h-80 rounded-2xl border border-base-200 bg-base-100"></div>
            </div>
            BLADE);
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.inventory-sold-products-card', [
            'soldProducts' => $this->soldProducts(),
        ]);
    }

    private function soldProducts(): Collection
    {
        return collect($this->rememberCache(
            $this->cacheKey('inventory', 'sold-products-card', $this->startDate, $this->endDate, (string) $this->customerId, (string) $this->productId, (string) $this->employeeId),
            fn (): array => $this->buildSoldProductsReport()->all(),
        ));
    }

    /**
     * Units sold per product/variant in the selected date range, ranked by quantity, with
     * fulfilled-only cost/profit/margin — see class docblock.
     */
    private function buildSoldProductsReport(): Collection
    {
        $orderIds = $this->scopedOrderIds();

        if ($orderIds->isEmpty()) {
            return collect();
        }

        $rows = SaleOrderItem::query()
            ->whereIn('sale_order_id', $orderIds)
            ->when($this->productId, fn ($query) => $query->where('product_id', $this->productId))
            ->selectRaw('
                product_id, variant_id,
                SUM(qty_ordered) as qty_sold,
                SUM(line_total_local) as revenue_local,
                COUNT(DISTINCT sale_order_id) as orders_count,
                SUM(qty_fulfilled * unit_price_amount) as fulfilled_revenue_amount,
                SUM(qty_fulfilled * unit_cost_amount) as cost_amount
            ')
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

            $fulfilledRevenue = round((float) $row->fulfilled_revenue_amount, 2);
            $cost = round((float) $row->cost_amount, 2);
            $profit = round($fulfilledRevenue - $cost, 2);

            return [
                'product_id'    => $row->product_id,
                'variant_id'    => $row->variant_id,
                'name'          => $product?->name ?? $variant?->display_name ?? ('Product #' . $row->product_id),
                'sku'           => $variant?->sku ?: $product?->sku,
                'qty_sold'      => round((float) $row->qty_sold, 2),
                'revenue_local' => round((float) $row->revenue_local, 2),
                'orders_count'  => (int) $row->orders_count,
                'cost_amount'   => $cost,
                'profit_amount' => $profit,
                'margin_pct'    => $fulfilledRevenue > 0.0 ? round($profit / $fulfilledRevenue * 100, 1) : null,
            ];
        })->values();
    }
}

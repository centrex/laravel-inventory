<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Http\Livewire\Transactions\Concerns\ScopesSalesReport;
use Centrex\Inventory\Models\{Product, ProductVariant, SaleReturnItem};
use Centrex\TallUi\Concerns\CachesData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Blade, Gate};
use Livewire\Component;

/**
 * Sales Report's "Returned Products" tab, scoped to the same date/customer/product/employee
 * filters as Sale Statistics / Recent Sales / Sold Products (see ScopesSalesReport) — the
 * counterpart to InventorySoldProductsCard, but reading from posted SaleReturn/SaleReturnItem
 * rows instead of SaleOrder/SaleOrderItem. Only 'posted' returns count (see
 * ScopesSalesReport::scopedSaleReturnsQuery()); draft returns haven't been received back
 * into stock yet.
 */
class InventoryReturnedProductsCard extends Component
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
            <div role="status" aria-label="Loading returned products" class="animate-pulse">
                <div class="h-80 rounded-2xl border border-base-200 bg-base-100"></div>
            </div>
            BLADE);
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.inventory-returned-products-card', [
            'returnedProducts' => $this->returnedProducts(),
        ]);
    }

    private function returnedProducts(): Collection
    {
        return collect($this->rememberCache(
            $this->cacheKey('inventory', 'returned-products-card', $this->startDate, $this->endDate, (string) $this->customerId, (string) $this->productId, (string) $this->employeeId),
            fn (): array => $this->buildReturnedProductsReport()->all(),
        ));
    }

    /** Units returned per product/variant in the selected date range, ranked by quantity. */
    private function buildReturnedProductsReport(): Collection
    {
        $returnIds = $this->scopedReturnIds();

        if ($returnIds->isEmpty()) {
            return collect();
        }

        $rows = SaleReturnItem::query()
            ->whereIn('sale_return_id', $returnIds)
            ->when($this->productId, fn ($query) => $query->where('product_id', $this->productId))
            ->selectRaw('
                product_id, variant_id,
                SUM(qty_returned) as qty_returned,
                SUM(line_total_amount) as value_local,
                SUM(qty_returned * unit_cost_amount) as cost_amount,
                COUNT(DISTINCT sale_return_id) as returns_count
            ')
            ->groupBy('product_id', 'variant_id')
            ->orderByDesc('qty_returned')
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

        return $rows->map(function (SaleReturnItem $row) use ($products, $variants): array {
            $product = $products->get((int) $row->product_id);
            $variant = $row->variant_id ? $variants->get((int) $row->variant_id) : null;

            return [
                'product_id'    => $row->product_id,
                'variant_id'    => $row->variant_id,
                'name'          => $product?->name ?? $variant?->display_name ?? ('Product #' . $row->product_id),
                'sku'           => $variant?->sku ?: $product?->sku,
                'qty_returned'  => round((float) $row->qty_returned, 2),
                'value_local'   => round((float) $row->value_local, 2),
                'cost_amount'   => round((float) $row->cost_amount, 2),
                'returns_count' => (int) $row->returns_count,
            ];
        })->values();
    }
}

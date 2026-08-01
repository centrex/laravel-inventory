<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Warehouse, WarehouseProduct};
use Centrex\TallUi\Concerns\CachesData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Blade, Gate};
use Livewire\Component;

/**
 * Split out of StockReportPage's "Low Stock" tab so it lazy-loads and caches independently
 * of the Stock Valuation tab. Filter input comes from the request query string rather than
 * #[Url] — a lazy component only actually mounts on the follow-up AJAX request, by which
 * point #[Url] binding on the initial full-page render has already happened (see
 * InventoryStockAgingCard's docblock for the same reasoning).
 */
class InventoryLowStockCard extends Component
{
    use CachesData;

    public ?int $warehouseId = null;

    public function mount(): void
    {
        Gate::authorize('inventory.reports.view');

        $this->warehouseId = request()->filled('warehouse') ? request()->integer('warehouse') : null;
        $this->cacheTtl = 120;
    }

    public function placeholder(): string
    {
        return Blade::render(<<<'BLADE'
            <div role="status" aria-label="Loading low stock" class="animate-pulse">
                <div class="rounded-2xl border border-base-200 bg-base-100 p-5">
                    <div class="h-4 w-32 rounded bg-base-300 mb-5"></div>
                    <div class="h-64 rounded-xl bg-base-200"></div>
                </div>
            </div>
            BLADE);
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.inventory-low-stock-card', [
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'name']),
            'lowStock'   => $this->lowStockItems(),
        ]);
    }

    /**
     * Caches a plain array, not the WarehouseProduct Eloquent Collection itself — see
     * InventoryStockAgingCard's docblock for why: the cache store serializes this with PHP's
     * native serialize(), and a Collection surviving into that blob comes back as
     * __PHP_Incomplete_Class if its class isn't autoloaded yet on the next request.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function lowStockItems(): Collection
    {
        return collect($this->rememberCache(
            $this->cacheKey('inventory', 'low-stock-card', (string) $this->warehouseId),
            fn (): array => app(Inventory::class)->getLowStockItems($this->warehouseId)
                ->map(fn (WarehouseProduct $wp): array => [
                    'product_name'   => $wp->product?->name,
                    'product_sku'    => $wp->product?->sku,
                    'warehouse_name' => $wp->warehouse?->name,
                    'qty_available'  => $wp->qtyAvailable(),
                    'reorder_point'  => (float) $wp->reorder_point,
                ])
                ->values()
                ->all(),
        ));
    }
}

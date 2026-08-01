<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\Warehouse;
use Centrex\TallUi\Concerns\CachesData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Blade, Gate};
use Livewire\Component;

/**
 * Split out of StockReportPage's "Stock Valuation" tab so it lazy-loads and caches
 * independently of the Low Stock tab — see InventoryLowStockCard's docblock for why the
 * filter is read from the request query string instead of #[Url].
 */
class InventoryStockValuationCard extends Component
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
            <div role="status" aria-label="Loading stock valuation" class="animate-pulse">
                <div class="rounded-2xl border border-base-200 bg-base-100 p-5">
                    <div class="h-4 w-40 rounded bg-base-300 mb-5"></div>
                    <div class="stats shadow w-full mb-4">
                        @for ($i = 0; $i < 2; $i++)
                            <div class="stat"><div class="h-3 w-20 rounded bg-base-300 mb-2"></div><div class="h-6 w-24 rounded bg-base-300"></div></div>
                        @endfor
                    </div>
                    <div class="h-64 rounded-xl bg-base-200"></div>
                </div>
            </div>
            BLADE);
    }

    public function render(): View
    {
        $inventory = app(Inventory::class);
        $valuation = $this->valuationRows();

        return view('inventory::livewire.transactions.inventory-stock-valuation-card', [
            'warehouses'      => Warehouse::query()->orderBy('name')->get(['id', 'name']),
            'valuation'       => $valuation,
            'totalStockValue' => $inventory->getStockValue($this->warehouseId),
            'productCount'    => $valuation->pluck('sku')->unique()->count(),
        ]);
    }

    /**
     * Caches a plain array, not the Collection itself — see InventoryStockAgingCard's
     * docblock for why: the cache store serializes this with PHP's native serialize(), and
     * a Collection surviving into that blob comes back as __PHP_Incomplete_Class if its
     * class isn't autoloaded yet on the next request.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function valuationRows(): Collection
    {
        return collect($this->rememberCache(
            $this->cacheKey('inventory', 'stock-valuation-card', (string) $this->warehouseId),
            fn (): array => app(Inventory::class)->stockValuationReport($this->warehouseId)->values()->all(),
        ));
    }
}

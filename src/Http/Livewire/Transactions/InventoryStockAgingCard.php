<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\Warehouse;
use Centrex\Inventory\Support\AgingReportExcelExporter;
use Centrex\TallUi\Concerns\CachesData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Blade, Gate};
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Split out of AgingReportPage's "Stock Aging" tab so it lazy-loads and caches
 * independently of the Due Aging tab — stockAgingReport() replays a FIFO trace across
 * every warehouse×product×variant with stock on hand, the more expensive of the two
 * reports, and there's no reason to pay for it before the user has looked at this tab.
 *
 * Filter input comes from the request query string rather than #[Url] (matching the
 * other lazy report cards on this dashboard) — #[Url] binds on the initial full-page
 * render, but a lazy component only actually mounts on the follow-up AJAX request.
 */
class InventoryStockAgingCard extends Component
{
    use CachesData;

    public ?int $warehouseId = null;

    public function mount(): void
    {
        Gate::authorize('inventory.reports.view');

        $this->warehouseId = request()->filled('warehouse') ? request()->integer('warehouse') : null;
        $this->cacheTtl = 120;
    }

    public function exportExcel(): StreamedResponse
    {
        return AgingReportExcelExporter::downloadStockAging(
            $this->stockAgingRows(),
            'stock-aging-' . now()->format('Ymd-His') . '.xlsx',
        );
    }

    public function placeholder(): string
    {
        return Blade::render(<<<'BLADE'
            <div role="status" aria-label="Loading stock aging" class="animate-pulse">
                <div class="rounded-2xl border border-base-200 bg-base-100 p-5">
                    <div class="h-4 w-40 rounded bg-base-300 mb-5"></div>
                    <div class="stats shadow w-full mb-4">
                        @for ($i = 0; $i < 5; $i++)
                            <div class="stat"><div class="h-3 w-16 rounded bg-base-300 mb-2"></div><div class="h-6 w-20 rounded bg-base-300"></div></div>
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

        return view('inventory::livewire.transactions.inventory-stock-aging-card', [
            'warehouses'        => Warehouse::query()->orderBy('name')->get(['id', 'name']),
            'stockAging'        => $this->stockAgingRows(),
            'stockAgingSummary' => $inventory->stockAgingSummary($this->warehouseId),
        ]);
    }

    /**
     * Caches a plain array, not the Collection itself — the cache store serializes this
     * with PHP's native serialize(), and a Collection surviving into that blob comes back
     * as __PHP_Incomplete_Class if its class isn't autoloaded yet on the next request, so
     * a later iteration throws deep inside the view. Re-wrapped in collect() on the way
     * out so render()/exportExcel() keep working with a Collection as before.
     *
     * @return Collection<int, mixed>
     */
    private function stockAgingRows(): Collection
    {
        return collect($this->rememberCache(
            $this->cacheKey('inventory', 'stock-aging-card', (string) $this->warehouseId),
            fn (): array => app(Inventory::class)->stockAgingReport($this->warehouseId)
                ->sortByDesc('oldest_days_in_stock')
                ->values()
                ->all(),
        ));
    }
}

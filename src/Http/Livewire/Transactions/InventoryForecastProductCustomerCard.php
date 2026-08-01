<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Http\Livewire\Transactions\Concerns\CachesSalesForecast;
use Centrex\TallUi\Concerns\CachesData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\{Blade, Gate};
use Livewire\Component;

/**
 * Split out of ForecastReportPage — the Product Forecast and Customer Forecast tables.
 * See InventoryForecastSummaryCard's docblock for the shared cache key and wire:key
 * remount-on-filter-change reasoning.
 */
class InventoryForecastProductCustomerCard extends Component
{
    use CachesData;
    use CachesSalesForecast;

    public int $lookbackDays = 90;

    public int $forecastDays = 90;

    public function mount(int $lookbackDays = 90, int $forecastDays = 90): void
    {
        Gate::authorize('inventory.reports.view');

        $this->lookbackDays = $lookbackDays;
        $this->forecastDays = $forecastDays;
        $this->cacheTtl = 600;
    }

    public function placeholder(): string
    {
        return Blade::render(<<<'BLADE'
            <div role="status" aria-label="Loading product and customer forecast" class="animate-pulse">
                <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                    <div class="h-64 rounded-2xl border border-base-200 bg-base-100"></div>
                    <div class="h-64 rounded-2xl border border-base-200 bg-base-100"></div>
                </div>
            </div>
            BLADE);
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.inventory-forecast-product-customer-card', [
            'forecast' => $this->forecastData($this->lookbackDays, $this->forecastDays),
        ]);
    }
}

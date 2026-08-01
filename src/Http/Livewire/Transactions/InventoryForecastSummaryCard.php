<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Http\Livewire\Transactions\Concerns\CachesSalesForecast;
use Centrex\TallUi\Concerns\CachesData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\{Blade, Gate};
use Livewire\Component;

/**
 * Split out of ForecastReportPage — the headline summary stats plus the Demand/Cashflow/
 * Timeline cards, sharing a cache key (scoped to lookback/horizon) with
 * InventoryForecastProductCustomerCard and InventoryForecastGeoCard so
 * Inventory::salesForecast() — the heaviest computation in the codebase, see
 * InventoryForecastCard's docblock — only actually runs once per lookback/horizon
 * combination even though all three cards call it independently.
 *
 * ForecastReportPage re-keys this component (via wire:key) on lookback/horizon change so a
 * filter change forces a fresh lazy mount rather than being silently ignored.
 */
class InventoryForecastSummaryCard extends Component
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
            <div role="status" aria-label="Loading forecast summary" class="animate-pulse space-y-6">
                <div class="stats shadow w-full">
                    @for ($i = 0; $i < 4; $i++)
                        <div class="stat"><div class="h-3 w-20 rounded bg-base-300 mb-2"></div><div class="h-6 w-24 rounded bg-base-300"></div></div>
                    @endfor
                </div>
                <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                    <div class="h-48 rounded-2xl border border-base-200 bg-base-100"></div>
                    <div class="h-48 rounded-2xl border border-base-200 bg-base-100"></div>
                    <div class="h-48 rounded-2xl border border-base-200 bg-base-100"></div>
                </div>
            </div>
            BLADE);
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.inventory-forecast-summary-card', [
            'forecast' => $this->forecastData($this->lookbackDays, $this->forecastDays),
        ]);
    }
}

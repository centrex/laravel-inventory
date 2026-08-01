<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\{Layout, Url};
use Livewire\Component;

/**
 * Thin shell: page header + lookback/horizon filters. The actual report content lives in
 * InventoryForecastSummaryCard / InventoryForecastProductCustomerCard / InventoryForecastGeoCard,
 * each lazy-loaded (and sharing one cached Inventory::salesForecast() call per lookback/
 * horizon combination — see CachesSalesForecast) so this page's own render stays cheap.
 *
 * Each card is keyed by wire:key="...-{{ $lookbackDays }}-{{ $forecastDays }}" in the Blade
 * view — changing either filter changes the key, which makes Livewire tear down and
 * re-mount (and re-fetch, via `lazy`) each card instead of silently keeping stale data.
 */
#[Layout('layouts.app')]
class ForecastReportPage extends Component
{
    #[Url(as: 'lookback')]
    public int $lookbackDays = 90;

    #[Url(as: 'horizon')]
    public int $forecastDays = 90;

    public function mount(): void
    {
        Gate::authorize('inventory.reports.view');
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.forecast-report');
    }
}

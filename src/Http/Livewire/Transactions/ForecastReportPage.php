<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Inventory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\{Layout, Url};
use Livewire\Component;

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
        $forecast = app(Inventory::class)->salesForecast(
            lookbackDays: $this->lookbackDays,
            forecastDays: $this->forecastDays,
        );

        return view('inventory::livewire.transactions.forecast-report', [
            'forecast' => $forecast,
        ]);
    }
}

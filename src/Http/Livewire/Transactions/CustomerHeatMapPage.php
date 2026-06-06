<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Inventory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CustomerHeatMapPage extends Component
{
    public string $startDate = '';

    public string $endDate = '';

    public string $metric = 'revenue';

    public function mount(): void
    {
        Gate::authorize('inventory.reports.view');
        $this->endDate   = now()->toDateString();
        $this->startDate = now()->subDays(89)->toDateString();
    }

    public function render(): View
    {
        $heatmap = app(Inventory::class)->customerSalesHeatmap(
            startDate: $this->startDate,
            endDate:   $this->endDate,
            metric:    $this->metric,
        );

        return view('inventory::livewire.transactions.customer-heat-map', [
            'heatmap' => $heatmap,
        ]);
    }
}

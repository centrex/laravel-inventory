<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Landing page linking out to the dedicated Sales, Purchase, Stock, and Forecast
 * report pages — kept as its own class/route (rather than renamed) since it's
 * already referenced as `inventory.reports.index` from the dashboard and other views.
 */
#[Layout('layouts.app')]
class InventoryReportsPage extends Component
{
    public function mount(): void
    {
        Gate::authorize('inventory.reports.view');
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.inventory-reports');
    }
}

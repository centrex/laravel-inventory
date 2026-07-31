<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Support\InventoryReportsExporter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * Every report page's data, in full (not the on-screen capped/paginated rows), as one
     * .xlsx with a Summary tab plus a detail tab per report — see InventoryReportsExporter.
     */
    public function exportAll(): StreamedResponse
    {
        Gate::authorize('inventory.reports.view');

        return InventoryReportsExporter::download([], 'inventory-reports-' . now()->format('Ymd-His') . '.xlsx');
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.inventory-reports');
    }
}

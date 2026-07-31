<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Thin shell: page header + tab bar. The actual report content lives in
 * InventoryStockAgingCard / InventoryDueAgingCard, each lazy-loaded per tab so neither
 * report is computed until its tab is actually rendered.
 */
#[Layout('layouts.app')]
class AgingReportPage extends Component
{
    public function mount(): void
    {
        Gate::authorize('inventory.reports.view');
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.aging-report');
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\Warehouse;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\{Layout, Url};
use Livewire\Component;

#[Layout('layouts.app')]
class AgingReportPage extends Component
{
    #[Url(as: 'warehouse', except: '')]
    public ?int $warehouseId = null;

    public function mount(): void
    {
        Gate::authorize('inventory.reports.view');
    }

    public function render(): View
    {
        $inventory = app(Inventory::class);
        $warehouses = Warehouse::query()->orderBy('name')->get(['id', 'name']);

        $stockAging = $inventory->stockAgingReport($this->warehouseId)
            ->sortByDesc('oldest_days_in_stock')
            ->values();
        $dueAging = $inventory->dueAgingReport()->sortByDesc('days_overdue')->values();

        return view('inventory::livewire.transactions.aging-report', [
            'warehouses'        => $warehouses,
            'stockAging'        => $stockAging,
            'stockAgingSummary' => $inventory->stockAgingSummary($this->warehouseId),
            'dueAging'          => $dueAging,
            'dueAgingSummary'   => $inventory->dueAgingSummary(),
        ]);
    }
}

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
class StockReportPage extends Component
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

        $valuation = $inventory->stockValuationReport($this->warehouseId);
        $lowStock = $inventory->getLowStockItems($this->warehouseId);

        return view('inventory::livewire.transactions.stock-report', [
            'warehouses'      => $warehouses,
            'valuation'       => $valuation,
            'lowStock'        => $lowStock,
            'totalStockValue' => $inventory->getStockValue($this->warehouseId),
            'productCount'    => $valuation->pluck('sku')->unique()->count(),
        ]);
    }
}

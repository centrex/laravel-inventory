<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Entities;

use Centrex\Inventory\Concerns\ShowsAuditTrail;
use Centrex\Inventory\Models\WarehouseProduct;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\{Layout, On};
use Livewire\Component;

#[Layout('layouts.app')]
class WarehouseStockIndexPage extends Component
{
    use ShowsAuditTrail;

    public function mount(): void
    {
        Gate::authorize('inventory.stock-data.view');
    }

    #[On('warehouse-stock-table:audit')]
    public function openWarehouseStockAuditTrail(int $id): void
    {
        $stock = WarehouseProduct::findOrFail($id);
        $this->openAuditTrail($stock::class, $stock->getKey(), $stock->sku ?: ('Warehouse Stock #' . $stock->getKey()));
    }

    #[On('warehouse-stock-table:delete')]
    public function delete(int $id): void
    {
        WarehouseProduct::findOrFail($id)->delete();

        $this->dispatch('notify', type: 'success', message: 'Record deleted.');
        $this->dispatch('warehouse-stock-table:refresh');
    }

    public function render(): View
    {
        return view('inventory::livewire.entities.warehouse-stock-index');
    }
}

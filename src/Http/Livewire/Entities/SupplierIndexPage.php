<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Entities;

use Centrex\Inventory\Concerns\ShowsAuditTrail;
use Centrex\Inventory\Models\Supplier;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\{Layout, On};
use Livewire\Component;

#[Layout('layouts.app')]
class SupplierIndexPage extends Component
{
    use ShowsAuditTrail;

    public function mount(): void
    {
        Gate::authorize('inventory.master-data.view');
    }

    #[On('supplier-table:audit')]
    public function openSupplierAuditTrail(int $id): void
    {
        $supplier = Supplier::findOrFail($id);
        $this->openAuditTrail($supplier::class, $supplier->getKey(), $supplier->name);
    }

    #[On('supplier-table:delete')]
    public function delete(int $id): void
    {
        Supplier::findOrFail($id)->delete();

        $this->dispatch('notify', type: 'success', message: 'Record deleted.');
        $this->dispatch('supplier-table:refresh');
    }

    public function render(): View
    {
        return view('inventory::livewire.entities.supplier-index');
    }
}

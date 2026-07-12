<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Entities;

use Centrex\Inventory\Concerns\ShowsAuditTrail;
use Centrex\Inventory\Models\Customer;
use Centrex\Inventory\Support\CommercialTeamAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\{Layout, On};
use Livewire\Component;

#[Layout('layouts.app')]
class CustomerIndexPage extends Component
{
    use ShowsAuditTrail;

    public function mount(): void
    {
        Gate::authorize('inventory.master-data.view');
    }

    #[On('customer-table:audit')]
    public function openCustomerAuditTrail(int $id): void
    {
        $customer = Customer::findOrFail($id);
        $this->openAuditTrail($customer::class, $customer->getKey(), $customer->name);
    }

    #[On('customer-table:delete')]
    public function delete(int $id): void
    {
        $query = Customer::query();
        CommercialTeamAccess::applySalesScope($query);

        $query->findOrFail($id)->delete();

        $this->dispatch('notify', type: 'success', message: 'Record deleted.');
        $this->dispatch('customer-table:refresh');
    }

    public function render(): View
    {
        return view('inventory::livewire.entities.customer-index');
    }
}

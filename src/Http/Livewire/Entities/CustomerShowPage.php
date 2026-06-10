<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Entities;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\Customer;
use Centrex\Inventory\Support\CommercialTeamAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CustomerShowPage extends Component
{
    public int $recordId;

    public function mount(int $recordId): void
    {
        $this->recordId = $recordId;
    }

    public function render(): View
    {
        $query = Customer::query();
        CommercialTeamAccess::applySalesScope($query);
        $customer = $query->findOrFail($this->recordId);

        $inventory = app(Inventory::class);

        return view('inventory::livewire.entities.customer-show', [
            'customer'               => $customer,
            'addresses'              => $customer->addresses()->orderByDesc('is_primary')->orderByDesc('created_at')->get(),
            'customerCreditSnapshot' => $inventory->customerCreditSnapshot($this->recordId),
            'customerAnalytics'      => $inventory->customerAnalytics($this->recordId),
            'customerHistory'        => $inventory->customerHistory($this->recordId),
        ]);
    }
}

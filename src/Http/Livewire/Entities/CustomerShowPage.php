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
    // Not strictly `int` — Livewire's nested-component hydration sets this property
    // directly (bypassing mount()'s type coercion), and a round-tripped string value
    // would otherwise throw a TypeError. Cast to int at each point of use instead.
    public int|string $recordId;

    public function mount(int $recordId): void
    {
        $this->recordId = $recordId;
    }

    public function render(): View
    {
        $recordId = (int) $this->recordId;

        $query = Customer::query();
        CommercialTeamAccess::applySalesScope($query);
        $customer = $query->findOrFail($recordId);

        $inventory = app(Inventory::class);

        return view('inventory::livewire.entities.customer-show', [
            'customer'               => $customer,
            'addresses'              => $customer->addresses()->orderByDesc('is_primary')->orderByDesc('created_at')->get(),
            'customerCreditSnapshot' => $inventory->customerCreditSnapshot($recordId),
            'customerAnalytics'      => $inventory->customerAnalytics($recordId),
            'customerHistory'        => $inventory->customerHistory($recordId),
        ]);
    }
}

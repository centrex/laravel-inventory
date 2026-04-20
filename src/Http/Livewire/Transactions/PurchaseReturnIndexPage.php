<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Models\PurchaseReturn;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\{Component, WithPagination};

#[Layout('layouts.app')]
class PurchaseReturnIndexPage extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $query = PurchaseReturn::query()
            ->with(['supplier', 'warehouse', 'purchaseOrder'])
            ->latest('returned_at')
            ->latest('id');

        if ($this->search !== '') {
            $search = $this->search;
            $query->where(function ($builder) use ($search): void {
                $builder->where('return_number', 'like', '%' . $search . '%')
                    ->orWhere('notes', 'like', '%' . $search . '%');
            });
        }

        return view('inventory::livewire.transactions.purchase-return-index', [
            'returns' => $query->paginate(15),
        ]);
    }
}

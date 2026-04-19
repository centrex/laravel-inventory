<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Models\SaleOrder;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\{Component, WithPagination};

#[Layout('layouts.app')]
class SaleOrderIndexPage extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $query = SaleOrder::query()
            ->with(['customer', 'warehouse'])
            ->latest('ordered_at')
            ->latest('id');

        if ($this->search !== '') {
            $search = $this->search;
            $query->where(function ($builder) use ($search): void {
                $builder->where('so_number', 'like', '%' . $search . '%')
                    ->orWhere('notes', 'like', '%' . $search . '%')
                    ->orWhereHas('customer', fn ($customerQuery) => $customerQuery->where('name', 'like', '%' . $search . '%'))
                    ->orWhereHas('warehouse', fn ($warehouseQuery) => $warehouseQuery->where('name', 'like', '%' . $search . '%'));
            });
        }

        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        return view('inventory::livewire.transactions.sale-order-index', [
            'orders'        => $query->paginate(15),
            'statusOptions' => [
                'draft'      => 'Draft',
                'confirmed'  => 'Confirmed',
                'processing' => 'Processing',
                'partial'    => 'Partially Fulfilled',
                'fulfilled'  => 'Fulfilled',
                'cancelled'  => 'Cancelled',
                'returned'   => 'Returned',
            ],
        ]);
    }
}

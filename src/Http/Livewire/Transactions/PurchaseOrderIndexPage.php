<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Models\PurchaseOrder;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\{Component, WithPagination};

#[Layout('layouts.app')]
class PurchaseOrderIndexPage extends Component
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
        $query = PurchaseOrder::query()
            ->with(['supplier', 'warehouse'])
            ->latest('ordered_at')
            ->latest('id');

        if ($this->search !== '') {
            $search = $this->search;
            $query->where(function ($builder) use ($search): void {
                $builder->where('po_number', 'like', '%' . $search . '%')
                    ->orWhere('notes', 'like', '%' . $search . '%')
                    ->orWhereHas('supplier', fn ($supplierQuery) => $supplierQuery->where('name', 'like', '%' . $search . '%'))
                    ->orWhereHas('warehouse', fn ($warehouseQuery) => $warehouseQuery->where('name', 'like', '%' . $search . '%'));
            });
        }

        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        return view('inventory::livewire.transactions.purchase-order-index', [
            'orders'        => $query->paginate(15),
            'statusOptions' => [
                'draft'     => 'Draft',
                'submitted' => 'Submitted',
                'confirmed' => 'Confirmed',
                'partial'   => 'Partially Received',
                'received'  => 'Received',
                'cancelled' => 'Cancelled',
            ],
        ]);
    }
}

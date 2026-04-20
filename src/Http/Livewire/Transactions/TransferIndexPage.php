<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Models\Transfer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\{Component, WithPagination};

#[Layout('layouts.app')]
class TransferIndexPage extends Component
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
        $query = Transfer::query()
            ->with(['fromWarehouse', 'toWarehouse'])
            ->latest('created_at')
            ->latest('id');

        if ($this->search !== '') {
            $search = $this->search;
            $query->where(function ($builder) use ($search): void {
                $builder->where('transfer_number', 'like', '%' . $search . '%')
                    ->orWhere('notes', 'like', '%' . $search . '%')
                    ->orWhereHas('fromWarehouse', fn ($warehouseQuery) => $warehouseQuery->where('name', 'like', '%' . $search . '%'))
                    ->orWhereHas('toWarehouse', fn ($warehouseQuery) => $warehouseQuery->where('name', 'like', '%' . $search . '%'));
            });
        }

        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        return view('inventory::livewire.transactions.transfer-index', [
            'transfers'      => $query->paginate(15),
            'statusOptions'  => [
                'draft'      => 'Draft',
                'in_transit' => 'In Transit',
                'partial'    => 'Partially Received',
                'received'   => 'Received',
            ],
        ]);
    }
}

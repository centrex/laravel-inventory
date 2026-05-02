<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Models\SaleOrder;
use Centrex\Inventory\Support\CommercialTeamAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\{Component, WithPagination};

#[Layout('layouts.app')]
class SaleOrderIndexPage extends Component
{
    use WithPagination;

    public string $documentType = 'order';

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

    public function mount(string $documentType = 'order'): void
    {
        $this->documentType = $documentType === 'quotation' ? 'quotation' : 'order';
    }

    public function render(): View
    {
        $query = SaleOrder::query()
            ->with(['customer', 'warehouse'])
            ->where('document_type', $this->documentType)
            ->latest('ordered_at')
            ->latest('id');

        CommercialTeamAccess::applySalesScope($query);

        if ($this->search !== '') {
            $search = $this->search;
            $query->where(function ($builder) use ($search): void {
                $builder->where('so_number', 'like', '%' . $search . '%')
                    ->orWhere('notes', 'like', '%' . $search . '%')
                    ->orWhereHas('customer', fn ($customerQuery) => $customerQuery->where('name', 'like', '%' . $search . '%')
                        ->orWhere('organization_name', 'like', '%' . $search . '%'))
                    ->orWhereHas('warehouse', fn ($warehouseQuery) => $warehouseQuery->where('name', 'like', '%' . $search . '%'));
            });
        }

        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        return view('inventory::livewire.transactions.sale-order-index', [
            'orders'        => $query->paginate(15),
            'documentLabel' => $this->documentType === 'quotation' ? 'Quotations' : 'Sale Orders',
            'routeBase'     => $this->documentType === 'quotation' ? 'inventory.quotations' : 'inventory.sale-orders',
            'statusOptions' => [
                'draft'      => 'Draft',
                'confirmed'  => 'Confirmed',
                'processing' => 'Processing',
                'shipped'    => 'Shipped',
                'partial'    => 'Partially Fulfilled',
                'fulfilled'  => 'Fulfilled',
                'completed'  => 'Completed',
                'cancelled'  => 'Cancelled',
                'returned'   => 'Returned',
            ],
        ]);
    }
}

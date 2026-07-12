<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Concerns\ShowsAuditTrail;
use Centrex\Inventory\Models\PurchaseOrder;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\{Layout, On};
use Livewire\Component;

#[Layout('layouts.app')]
class PurchaseOrderIndexPage extends Component
{
    use ShowsAuditTrail;

    public string $documentType = 'order';

    public function mount(string $documentType = 'order'): void
    {
        Gate::authorize('inventory.purchase-orders.view');

        $this->documentType = $documentType === 'requisition' ? 'requisition' : 'order';
    }

    #[On('purchase-order-table:audit')]
    public function openPurchaseOrderAuditTrail(int $id): void
    {
        $order = PurchaseOrder::findOrFail($id);
        $this->openAuditTrail($order::class, $order->getKey(), $order->po_number);
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.purchase-order-index', [
            'documentLabel' => $this->documentType === 'requisition' ? 'Requisitions' : 'Purchase Orders',
            'routeBase'     => $this->documentType === 'requisition' ? 'inventory.requisitions' : 'inventory.purchase-orders',
        ]);
    }
}

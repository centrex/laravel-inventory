<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Concerns\ShowsAuditTrail;
use Centrex\Inventory\Support\CommercialTeamAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\{Layout, On};
use Livewire\Component;

#[Layout('layouts.app')]
class SaleOrderIndexPage extends Component
{
    use ShowsAuditTrail;

    public string $documentType = 'order';

    public function mount(string $documentType = 'order'): void
    {
        CommercialTeamAccess::authorizeAny(['sales.orders.manage', 'inventory.sale-orders.view', 'inventory.sale-orders.view-all']);

        $this->documentType = $documentType === 'quotation' ? 'quotation' : 'order';
    }

    #[On('sale-order-table:audit')]
    public function openSaleOrderAuditTrail(int $id): void
    {
        $order = \Centrex\Inventory\Models\SaleOrder::findOrFail($id);
        $this->openAuditTrail($order::class, $order->getKey(), $order->so_number);
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.sale-order-index', [
            'documentLabel' => $this->documentType === 'quotation' ? 'Quotations' : 'Sale Orders',
            'routeBase'     => $this->documentType === 'quotation' ? 'inventory.quotations' : 'inventory.sale-orders',
        ]);
    }
}

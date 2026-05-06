<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Models\PurchaseReturn;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PurchaseReturnShowPage extends Component
{
    public PurchaseReturn $record;

    public function mount(int $recordId): void
    {
        $this->record = PurchaseReturn::query()
            ->with(['warehouse', 'supplier', 'purchaseOrder', 'items.product', 'items.variant'])
            ->findOrFail($recordId);
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.purchase-return-show', [
            'record' => $this->record,
        ]);
    }
}

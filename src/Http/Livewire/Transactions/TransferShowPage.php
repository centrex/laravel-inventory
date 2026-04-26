<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\Transfer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class TransferShowPage extends Component
{
    public Transfer $record;

    public function mount(int $recordId): void
    {
        $this->record = Transfer::query()
            ->with(['fromWarehouse', 'toWarehouse', 'items.product', 'boxes.items.product'])
            ->findOrFail($recordId);
    }

    public function dispatchTransfer(): void
    {
        Gate::authorize('inventory.transfers.dispatch');

        $this->record = app(Inventory::class)->dispatchTransfer((int) $this->record->getKey());
        $this->dispatch('notify', type: 'success', message: "Transfer {$this->record->transfer_number} dispatched.");
    }

    public function receive(): void
    {
        Gate::authorize('inventory.transfers.receive');

        $this->record = app(Inventory::class)->receiveTransfer((int) $this->record->getKey());
        $this->dispatch('notify', type: 'success', message: "Transfer {$this->record->transfer_number} received.");
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.transfer-show', [
            'record'      => $this->record->fresh(['fromWarehouse', 'toWarehouse', 'items.product', 'boxes.items.product']),
            'canDispatch' => Gate::allows('inventory.transfers.dispatch'),
            'canReceive'  => Gate::allows('inventory.transfers.receive'),
        ]);
    }
}

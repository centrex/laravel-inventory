<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Exceptions\InsufficientStockException;
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

    public bool $showReceiveModal = false;

    public array $receiveQtys = [];

    public function mount(int $recordId): void
    {
        $this->record = Transfer::query()
            ->with(['fromWarehouse', 'toWarehouse', 'items.product', 'boxes.items.product'])
            ->findOrFail($recordId);
    }

    public function dispatchTransfer(): void
    {
        Gate::authorize('inventory.transfers.dispatch');

        try {
            $this->record = app(Inventory::class)->dispatchTransfer((int) $this->record->getKey());
            $this->dispatch('notify', type: 'success', message: "Transfer {$this->record->transfer_number} dispatched.");
        } catch (InsufficientStockException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function openReceiveModal(): void
    {
        $this->receiveQtys = [];

        foreach ($this->record->items as $item) {
            $remaining = max(0.0, (float) $item->qty_sent - (float) $item->qty_received);
            $this->receiveQtys[$item->id] = $remaining;
        }

        $this->showReceiveModal = true;
    }

    public function receivePartial(): void
    {
        Gate::authorize('inventory.transfers.receive');

        $qtys = collect($this->receiveQtys)
            ->filter(fn ($v) => (float) $v > 0)
            ->map(fn ($v) => (float) $v)
            ->all();

        $this->record = app(Inventory::class)->receiveTransfer((int) $this->record->getKey(), $qtys);
        $this->showReceiveModal = false;
        $this->dispatch('notify', type: 'success', message: "Transfer {$this->record->transfer_number} updated.");
    }

    public function receiveAll(): void
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

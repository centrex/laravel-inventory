<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Models\Adjustment;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class AdjustmentShowPage extends Component
{
    public Adjustment $record;

    public function mount(int $recordId): void
    {
        $this->record = Adjustment::query()
            ->with(['warehouse', 'items.product', 'items.variant'])
            ->findOrFail($recordId);
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.adjustment-show', [
            'record' => $this->record,
        ]);
    }
}

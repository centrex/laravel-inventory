<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Models\SaleReturn;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class SaleReturnShowPage extends Component
{
    public SaleReturn $record;

    public function mount(int $recordId): void
    {
        $this->record = SaleReturn::query()
            ->with(['warehouse', 'customer', 'saleOrder', 'items.product'])
            ->findOrFail($recordId);
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.sale-return-show', [
            'record' => $this->record,
        ]);
    }
}

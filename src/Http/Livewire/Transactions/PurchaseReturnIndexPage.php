<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Concerns\ShowsAuditTrail;
use Centrex\Inventory\Models\PurchaseReturn;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\{Layout, On};
use Livewire\Component;

#[Layout('layouts.app')]
class PurchaseReturnIndexPage extends Component
{
    use ShowsAuditTrail;

    #[On('purchase-return-table:audit')]
    public function openPurchaseReturnAuditTrail(int $id): void
    {
        $return = PurchaseReturn::findOrFail($id);
        $this->openAuditTrail($return::class, $return->getKey(), $return->return_number);
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.purchase-return-index');
    }
}

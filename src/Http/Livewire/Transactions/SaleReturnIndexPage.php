<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Concerns\ShowsAuditTrail;
use Centrex\Inventory\Models\SaleReturn;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\{Layout, On};
use Livewire\Component;

#[Layout('layouts.app')]
class SaleReturnIndexPage extends Component
{
    use ShowsAuditTrail;

    #[On('sale-return-table:audit')]
    public function openSaleReturnAuditTrail(int $id): void
    {
        $return = SaleReturn::findOrFail($id);
        $this->openAuditTrail($return::class, $return->getKey(), $return->return_number);
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.sale-return-index');
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Concerns\ShowsAuditTrail;
use Centrex\Inventory\Models\Adjustment;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\{Layout, On};
use Livewire\Component;

#[Layout('layouts.app')]
class AdjustmentIndexPage extends Component
{
    use ShowsAuditTrail;

    #[On('adjustment-table:audit')]
    public function openAdjustmentAuditTrail(int $id): void
    {
        $adjustment = Adjustment::findOrFail($id);
        $this->openAuditTrail($adjustment::class, $adjustment->getKey(), $adjustment->adjustment_number);
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.adjustment-index');
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Entities;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ProductPriceSheetIndexPage extends Component
{
    public function render(): View
    {
        return view('inventory::livewire.entities.product-price-sheet-index');
    }
}

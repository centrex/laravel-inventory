<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Entities;

use Centrex\Inventory\Concerns\ShowsAuditTrail;
use Centrex\Inventory\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\{Layout, On};
use Livewire\Component;

#[Layout('layouts.app')]
class ProductIndexPage extends Component
{
    use ShowsAuditTrail;

    public function mount(): void
    {
        Gate::authorize('inventory.master-data.view');
    }

    #[On('product-table:audit')]
    public function openProductAuditTrail(int $id): void
    {
        $product = Product::findOrFail($id);
        $this->openAuditTrail($product::class, $product->getKey(), $product->name);
    }

    #[On('product-table:delete')]
    public function delete(int $id): void
    {
        Product::findOrFail($id)->delete();

        $this->dispatch('notify', type: 'success', message: 'Record deleted.');
        $this->dispatch('product-table:refresh');
    }

    public function render(): View
    {
        return view('inventory::livewire.entities.product-index');
    }
}

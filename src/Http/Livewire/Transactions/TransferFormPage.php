<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Product, Warehouse};
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('inventory::layouts.app')]
class TransferFormPage extends Component
{
    public ?int $from_warehouse_id = null;

    public ?int $to_warehouse_id = null;

    public float $shipping_rate_per_kg = 0;

    public string $notes = '';

    public array $items = [];

    public function mount(): void
    {
        $this->items = [$this->blankItem()];
    }

    public function addItem(): void
    {
        $this->items[] = $this->blankItem();
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function save(): \Illuminate\Http\RedirectResponse
    {
        $validated = $this->validate([
            'from_warehouse_id'    => ['required', 'integer'],
            'to_warehouse_id'      => ['required', 'integer', 'different:from_warehouse_id'],
            'shipping_rate_per_kg' => ['nullable', 'numeric', 'min:0'],
            'notes'                => ['nullable', 'string'],
            'items'                => ['required', 'array', 'min:1'],
            'items.*.product_id'   => ['required', 'integer'],
            'items.*.qty_sent'     => ['required', 'numeric', 'gt:0'],
        ]);

        $transfer = app(Inventory::class)->createTransfer($validated);
        session()->flash('inventory.status', "Transfer {$transfer->transfer_number} created.");

        return redirect()->route('inventory.transfers.create');
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.transfer-form', [
            'warehouses' => Warehouse::query()->orderBy('name')->get(),
            'products'   => Product::query()->orderBy('name')->get(),
        ]);
    }

    private function blankItem(): array
    {
        return [
            'product_id' => null,
            'qty_sent'   => 1,
        ];
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Product, Supplier, Warehouse};
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('inventory::layouts.app')]
class PurchaseOrderFormPage extends Component
{
    public ?int $warehouse_id = null;
    public ?int $supplier_id = null;
    public string $currency = 'BDT';
    public ?float $exchange_rate = null;
    public float $tax_local = 0;
    public float $shipping_local = 0;
    public float $other_charges_amount = 0;
    public ?string $expected_at = null;
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
            'warehouse_id'               => ['required', 'integer'],
            'supplier_id'                => ['required', 'integer'],
            'currency'                   => ['required', 'string', 'size:3'],
            'exchange_rate'          => ['nullable', 'numeric', 'gt:0'],
            'tax_local'                  => ['nullable', 'numeric'],
            'shipping_local'             => ['nullable', 'numeric'],
            'other_charges_amount'          => ['nullable', 'numeric'],
            'expected_at'                => ['nullable', 'date'],
            'notes'                      => ['nullable', 'string'],
            'items'                      => ['required', 'array', 'min:1'],
            'items.*.product_id'         => ['required', 'integer'],
            'items.*.qty_ordered'        => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price_local'   => ['required', 'numeric', 'min:0'],
            'items.*.notes'              => ['nullable', 'string'],
        ]);

        $purchaseOrder = app(Inventory::class)->createPurchaseOrder($validated);
        session()->flash('inventory.status', "Purchase order {$purchaseOrder->po_number} created.");

        return redirect()->route('inventory.purchase-orders.create');
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.purchase-order-form', [
            'warehouses' => Warehouse::query()->orderBy('name')->get(),
            'suppliers'  => Supplier::query()->orderBy('name')->get(),
            'products'   => Product::query()->orderBy('name')->get(),
        ]);
    }

    private function blankItem(): array
    {
        return [
            'product_id'        => null,
            'qty_ordered'       => 1,
            'unit_price_local'  => 0,
            'notes'             => '',
        ];
    }
}

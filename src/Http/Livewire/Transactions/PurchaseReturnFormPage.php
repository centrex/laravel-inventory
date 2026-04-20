<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Product, PurchaseOrder, Supplier, Warehouse};
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PurchaseReturnFormPage extends Component
{
    public ?int $purchase_order_id = null;
    public ?int $warehouse_id = null;
    public ?int $supplier_id = null;
    public ?string $returned_at = null;
    public string $notes = '';
    public array $items = [];

    public function mount(): void
    {
        $this->returned_at = now()->toDateString();
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

    public function save()
    {
        $validated = $this->validate([
            'purchase_order_id'         => ['nullable', 'integer'],
            'warehouse_id'              => ['required', 'integer'],
            'supplier_id'               => ['required', 'integer'],
            'returned_at'               => ['nullable', 'date'],
            'notes'                     => ['nullable', 'string'],
            'items'                     => ['required', 'array', 'min:1'],
            'items.*.product_id'        => ['required', 'integer'],
            'items.*.qty_returned'      => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost_amount'  => ['nullable', 'numeric', 'min:0'],
            'items.*.notes'             => ['nullable', 'string'],
        ]);

        $purchaseReturn = app(Inventory::class)->createPurchaseReturn($validated);
        app(Inventory::class)->postPurchaseReturn((int) $purchaseReturn->getKey());

        session()->flash('inventory.status', "Purchase return {$purchaseReturn->return_number} posted.");

        return redirect()->route('inventory.purchase-returns.show', ['recordId' => $purchaseReturn->getKey()]);
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.purchase-return-form', [
            'purchaseOrders' => PurchaseOrder::query()->where('document_type', 'order')->orderByDesc('ordered_at')->limit(100)->get(),
            'warehouses'     => Warehouse::query()->orderBy('name')->get(),
            'suppliers'      => Supplier::query()->orderBy('name')->get(),
            'products'       => Product::query()->orderBy('name')->get(),
        ]);
    }

    private function blankItem(): array
    {
        return [
            'product_id'       => null,
            'qty_returned'     => 1,
            'unit_cost_amount' => null,
            'notes'            => '',
        ];
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Customer, Product, SaleOrder, Warehouse};
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class SaleReturnFormPage extends Component
{
    public ?int $sale_order_id = null;

    public ?int $warehouse_id = null;

    public ?int $customer_id = null;

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
            'sale_order_id'             => ['nullable', 'integer'],
            'warehouse_id'              => ['required', 'integer'],
            'customer_id'               => ['nullable', 'integer'],
            'returned_at'               => ['nullable', 'date'],
            'notes'                     => ['nullable', 'string'],
            'items'                     => ['required', 'array', 'min:1'],
            'items.*.product_id'        => ['required', 'integer'],
            'items.*.qty_returned'      => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_cost_amount'  => ['nullable', 'numeric', 'min:0'],
            'items.*.notes'             => ['nullable', 'string'],
        ]);

        $saleReturn = app(Inventory::class)->createSaleReturn($validated);
        app(Inventory::class)->postSaleReturn((int) $saleReturn->getKey());

        session()->flash('inventory.status', "Sale return {$saleReturn->return_number} posted.");

        return redirect()->route('inventory.sale-returns.show', ['recordId' => $saleReturn->getKey()]);
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.sale-return-form', [
            'saleOrders' => SaleOrder::query()->where('document_type', 'order')->orderByDesc('ordered_at')->limit(100)->get(),
            'warehouses' => Warehouse::query()->orderBy('name')->get(),
            'customers'  => Customer::query()->orderBy('name')->get(),
            'products'   => Product::query()->orderBy('name')->get(),
        ]);
    }

    private function blankItem(): array
    {
        return [
            'product_id'        => null,
            'qty_returned'      => 1,
            'unit_price_amount' => null,
            'unit_cost_amount'  => null,
            'notes'             => '',
        ];
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Enums\PriceTierCode;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Customer, PriceTier, Product, Warehouse};
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class SaleOrderFormPage extends Component
{
    public ?int $warehouse_id = null;

    public ?int $customer_id = null;

    public string $price_tier_code = 'retail';

    public string $currency = 'BDT';

    public ?float $exchange_rate = null;

    public float $tax_local = 0;

    public float $discount_local = 0;

    public string $notes = '';

    public array $items = [];

    public function mount(): void
    {
        $this->price_tier_code = PriceTierCode::RETAIL->value;
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
            'warehouse_id'             => ['required', 'integer'],
            'customer_id'              => ['nullable', 'integer'],
            'price_tier_code'          => ['required', 'string'],
            'currency'                 => ['required', 'string', 'size:3'],
            'exchange_rate'            => ['nullable', 'numeric', 'gt:0'],
            'tax_local'                => ['nullable', 'numeric'],
            'discount_local'           => ['nullable', 'numeric'],
            'notes'                    => ['nullable', 'string'],
            'items'                    => ['required', 'array', 'min:1'],
            'items.*.product_id'       => ['required', 'integer'],
            'items.*.qty_ordered'      => ['required', 'numeric', 'gt:0'],
            'items.*.price_tier_code'  => ['nullable', 'string'],
            'items.*.unit_price_local' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_pct'     => ['nullable', 'numeric', 'min:0'],
            'items.*.notes'            => ['nullable', 'string'],
        ]);

        $saleOrder = app(Inventory::class)->createSaleOrder($validated);
        session()->flash('inventory.status', "Sale order {$saleOrder->so_number} created.");

        return redirect()->route('inventory.sale-orders.create');
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.sale-order-form', [
            'warehouses' => Warehouse::query()->orderBy('name')->get(),
            'customers'  => Customer::query()->orderBy('name')->get(),
            'products'   => Product::query()->orderBy('name')->get(),
            'priceTiers' => PriceTier::query()->orderBy('sort_order')->get(),
        ]);
    }

    private function blankItem(): array
    {
        return [
            'product_id'       => null,
            'qty_ordered'      => 1,
            'price_tier_code'  => null,
            'unit_price_local' => null,
            'discount_pct'     => 0,
            'notes'            => '',
        ];
    }
}

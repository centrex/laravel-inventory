<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Enums\PriceTierCode;
use Centrex\Inventory\Models\{Customer, PriceTier, Product, Warehouse};
use Centrex\Inventory\Support\CartCheckoutService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PosTerminalPage extends Component
{
    public ?int $warehouse_id = null;

    public ?int $customer_id = null;

    public string $price_tier_code = 'retail';

    public string $currency = 'BDT';

    public ?int $product_id = null;

    public int $qty = 1;

    public ?float $unit_price_local = null;

    public string $notes = '';

    public ?string $errorMessage = null;

    public function mount(): void
    {
        $this->price_tier_code = PriceTierCode::RETAIL->value;
    }

    public function updatedProductId(): void
    {
        if (!$this->product_id) {
            return;
        }

        $product = Product::find($this->product_id);

        if (!$product) {
            return;
        }

        $this->unit_price_local ??= (float) ($product->meta['default_price'] ?? 0);
    }

    public function addProduct(): void
    {
        $validated = $this->validate([
            'product_id'       => ['required', 'integer'],
            'qty'              => ['required', 'integer', 'min:1'],
            'unit_price_local' => ['required', 'numeric', 'min:0'],
        ]);

        if (!class_exists(\Centrex\Cart\Cart::class)) {
            $this->errorMessage = 'Cart package is not available.';

            return;
        }

        $product = Product::findOrFail((int) $validated['product_id']);
        app(\Centrex\Cart\Cart::class)->instance('pos')->add(
            $product->id,
            $product->name,
            (int) $validated['qty'],
            (float) $validated['unit_price_local'],
            [
                'sku'   => $product->sku,
                'notes' => $this->notes ?: null,
            ],
        );

        $this->product_id = null;
        $this->qty = 1;
        $this->unit_price_local = null;
        $this->notes = '';
        $this->errorMessage = null;
    }

    public function removeItem(string $rowId): void
    {
        if (!class_exists(\Centrex\Cart\Cart::class)) {
            return;
        }

        app(\Centrex\Cart\Cart::class)->instance('pos')->remove($rowId);
    }

    public function checkout(): \Illuminate\Http\RedirectResponse
    {
        $validated = $this->validate([
            'warehouse_id'    => ['required', 'integer'],
            'customer_id'     => ['nullable', 'integer'],
            'price_tier_code' => ['required', 'string'],
            'currency'        => ['required', 'string', 'size:3'],
        ]);

        $saleOrder = app(CartCheckoutService::class)->checkout([
            'warehouse_id'    => (int) $validated['warehouse_id'],
            'customer_id'     => $validated['customer_id'] ?? null,
            'price_tier_code' => $validated['price_tier_code'],
            'currency'        => $validated['currency'],
            'cart_instance'   => 'pos',
            'confirm'         => true,
            'reserve'         => true,
            'fulfill'         => true,
            'clear_cart'      => true,
            'source'          => 'pos-terminal',
            'context'         => ['ui' => 'livewire'],
        ], 'pos');

        session()->flash('inventory.status', "POS checkout completed as {$saleOrder->so_number}.");

        return redirect()->route('inventory.pos.index');
    }

    public function render(): View
    {
        $cart = class_exists(\Centrex\Cart\Cart::class)
            ? app(\Centrex\Cart\Cart::class)->instance('pos')
            : null;

        return view('inventory::livewire.transactions.pos-terminal', [
            'warehouses' => Warehouse::query()->orderBy('name')->get(),
            'customers'  => Customer::query()->orderBy('name')->get(),
            'products'   => Product::query()->orderBy('name')->get(),
            'priceTiers' => PriceTier::query()->orderBy('sort_order')->get(),
            'cartItems'  => $cart?->content() ?? collect(),
            'cartCount'  => $cart?->count() ?? 0,
            'cartTotal'  => $cart?->total() ?? 0,
        ]);
    }
}

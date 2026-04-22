<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Enums\PriceTierCode;
use Centrex\Inventory\Models\{Customer, Product, Warehouse};

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('inventory::layouts.pos')]
class PosTerminalPage extends Component
{
    public ?int $warehouse_id = null;

    public ?int $customer_id = null;

    public string $price_tier_code = 'b2b_pos';

    public string $currency = 'BDT';

    public string $search = '';

    public ?int $product_id = null;

    public int $qty = 1;

    public ?float $unit_price_local = null;

    public string $notes = '';

    public ?string $errorMessage = null;

    public function mount(): void
    {
        $this->price_tier_code = PriceTierCode::B2B_RETAIL->value;

        $first = Warehouse::query()->orderBy('id')->first();

        if ($first) {
            $this->warehouse_id = $first->id;
        }
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

    public function tapProduct(int $id): void
    {
        if (!class_exists(\Centrex\Cart\Cart::class)) {
            $this->errorMessage = 'Cart package is not available.';

            return;
        }

        $product = Product::find($id);

        if (!$product) {
            return;
        }

        app(\Centrex\Cart\Cart::class)->instance('pos')->add(
            $product->id,
            $product->name,
            1,
            (float) ($product->meta['default_price'] ?? 0),
            ['sku' => $product->sku, 'notes' => null],
        );

        $this->errorMessage = null;
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

    public function incrementItem(string $rowId): void
    {
        if (!class_exists(\Centrex\Cart\Cart::class)) {
            return;
        }

        $cart = app(\Centrex\Cart\Cart::class)->instance('pos');
        $item = $cart->content()->get($rowId);

        if ($item) {
            $cart->update($rowId, $item->qty + 1);
        }
    }

    public function decrementItem(string $rowId): void
    {
        if (!class_exists(\Centrex\Cart\Cart::class)) {
            return;
        }

        $cart = app(\Centrex\Cart\Cart::class)->instance('pos');
        $item = $cart->content()->get($rowId);

        if ($item) {
            if ($item->qty <= 1) {
                $cart->remove($rowId);
            } else {
                $cart->update($rowId, $item->qty - 1);
            }
        }
    }

    public function removeItem(string $rowId): void
    {
        if (!class_exists(\Centrex\Cart\Cart::class)) {
            return;
        }

        app(\Centrex\Cart\Cart::class)->instance('pos')->remove($rowId);
    }

    public function clearCart(): void
    {
        if (!class_exists(\Centrex\Cart\Cart::class)) {
            return;
        }

        app(\Centrex\Cart\Cart::class)->instance('pos')->clear();
    }

    public function checkout(): \Illuminate\Http\RedirectResponse
    {
        $validated = $this->validate([
            'warehouse_id'    => ['required', 'integer'],
            'customer_id'     => ['nullable', 'integer'],
            'price_tier_code' => ['required', 'string'],
            'currency'        => ['required', 'string', 'size:3'],
        ]);

        if (!class_exists(\Centrex\Cart\Services\CartCheckoutService::class)) {
            session()->flash('inventory.error', 'centrex/laravel-cart is required for POS checkout.');

            return redirect()->back();
        }

        $saleOrder = app(\Centrex\Cart\Services\CartCheckoutService::class)->checkout([
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

        $products = Product::query()
            ->with([
                'media',
                'warehouseProducts' => fn ($q) => $q->where('warehouse_id', $this->warehouse_id),
            ])
            ->where('is_active', true)
            ->when(
                $this->warehouse_id,
                fn ($q) => $q->where(
                    fn ($inner) => $inner
                        ->where('is_stockable', false)
                        ->orWhereHas(
                            'warehouseProducts',
                            fn ($wp) => $wp
                                ->where('warehouse_id', $this->warehouse_id)
                                ->whereRaw('qty_on_hand - qty_reserved > 0'),
                        ),
                ),
            )
            ->when(
                $this->search,
                fn ($q) => $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('sku', 'like', "%{$this->search}%"),
            )
            ->orderBy('name')
            ->get();

        return view('inventory::livewire.transactions.pos-terminal', [
            'warehouses' => Warehouse::query()->orderBy('name')->get(),
            'customers'  => Customer::query()->orderBy('name')->get(),
            'products'   => $products,
            'priceTiers' => PriceTierCode::options(),
            'cartItems'  => $cart?->content() ?? collect(),
            'cartCount'  => $cart?->count() ?? 0,
            'cartTotal'  => $cart?->total() ?? 0,
        ]);
    }
}

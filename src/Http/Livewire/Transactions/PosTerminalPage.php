<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Enums\PriceTierCode;
use Centrex\Inventory\Models\{Customer, Product, Warehouse, WarehouseProduct};

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('inventory::layouts.pos')]
class PosTerminalPage extends Component
{
    public ?int $warehouse_id = null;

    public string $price_tier_code = 'b2b_pos';

    public string $currency = 'BDT';

    // Multi-tab state — IDs are stable (never reused after close)
    public int $activeTabId = 0;

    public int $nextTabId = 1;

    public array $tabIds = [0];

    public array $tabLabels = [0 => 'Tab 1'];

    public array $tabCustomers = [0 => null];

    public array $tabCouponCodes = [0 => ''];

    public array $tabSearches = [0 => ''];

    // Manual product-add form (shared, not per-tab)
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

    // ── Tab management ──────────────────────────────────────────────────────

    private function cartInstance(): string
    {
        return 'pos_tab_' . $this->activeTabId;
    }

    private function getCart(): \Centrex\Cart\Cart
    {
        return app(\Centrex\Cart\Cart::class)->instance($this->cartInstance());
    }

    /** Resolve sell price: warehouse-specific tier price → global tier price → meta fallback. */
    private function productPrice(Product $product): float
    {
        $price = $product->prices()
            ->where('price_tier_code', $this->price_tier_code)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', today()))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', today()))
            ->where(fn ($q) => $q->whereNull('warehouse_id')->orWhere('warehouse_id', $this->warehouse_id))
            ->orderByRaw('CASE WHEN warehouse_id IS NULL THEN 1 ELSE 0 END')
            ->value('price_local');

        return $price !== null ? (float) $price : (float) ($product->meta['default_price'] ?? 0);
    }

    /** Returns available qty for stockable products, PHP_INT_MAX for non-stockable. */
    private function availableQty(Product $product): int
    {
        if (!$product->is_stockable || !$this->warehouse_id) {
            return PHP_INT_MAX;
        }

        $wp = WarehouseProduct::query()
            ->where('warehouse_id', $this->warehouse_id)
            ->where('product_id', $product->id)
            ->first();

        return $wp ? (int) floor($wp->qtyAvailable()) : 0;
    }

    /** Total qty of a product already in the active tab's cart. */
    private function cartQtyForProduct(int $productId): int
    {
        if (!class_exists(\Centrex\Cart\Cart::class)) {
            return 0;
        }

        return (int) $this->getCart()->content()->where('id', $productId)->sum('qty');
    }

    public function switchTab(int $id): void
    {
        if (in_array($id, $this->tabIds, true)) {
            $this->activeTabId = $id;
            $this->errorMessage = null;
            $this->dispatch('focus-search');
        }
    }

    public function addTab(): void
    {
        if (count($this->tabIds) >= 5) {
            $this->errorMessage = 'Maximum 5 tabs allowed.';

            return;
        }

        $id = $this->nextTabId++;
        $this->tabIds[] = $id;
        $this->tabLabels[$id] = 'Tab ' . ($id + 1);
        $this->tabCustomers[$id] = null;
        $this->tabCouponCodes[$id] = '';
        $this->tabSearches[$id] = '';
        $this->activeTabId = $id;
        $this->errorMessage = null;
        $this->dispatch('focus-search');
    }

    public function closeTab(int $id): void
    {
        if (count($this->tabIds) <= 1) {
            return;
        }

        if (class_exists(\Centrex\Cart\Cart::class)) {
            app(\Centrex\Cart\Cart::class)->instance('pos_tab_' . $id)->clear();
        }

        $this->tabIds = array_values(array_filter($this->tabIds, fn ($t) => $t !== $id));
        unset($this->tabLabels[$id], $this->tabCustomers[$id], $this->tabCouponCodes[$id], $this->tabSearches[$id]);

        if ($this->activeTabId === $id) {
            $this->activeTabId = $this->tabIds[0];
        }

        $this->dispatch('focus-search');
    }

    // ── Barcode / search ────────────────────────────────────────────────────

    public function scanFromSearch(): void
    {
        $search = trim($this->tabSearches[$this->activeTabId] ?? '');

        if ($search === '') {
            return;
        }

        // Exact barcode or SKU match — typical scanner input
        $product = Product::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('barcode', $search)->orWhere('sku', $search))
            ->first();

        if ($product) {
            $this->tapProduct($product->id);
            $this->tabSearches[$this->activeTabId] = '';

            return;
        }

        // Fuzzy match that resolves to exactly one product
        $matches = Product::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%"))
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
            ->get();

        if ($matches->count() === 1) {
            $this->tapProduct($matches->first()->id);
            $this->tabSearches[$this->activeTabId] = '';
        }
    }

    public function clearSearch(): void
    {
        $this->tabSearches[$this->activeTabId] = '';
        $this->dispatch('focus-search');
    }

    // ── Cart helpers ────────────────────────────────────────────────────────

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

        $available = $this->availableQty($product);
        $inCart = $this->cartQtyForProduct($id);

        if ($inCart >= $available) {
            $this->errorMessage = "Max stock reached for {$product->name} ({$available} available).";

            return;
        }

        $this->getCart()->add(
            $product->id,
            $product->name,
            1,
            $this->productPrice($product),
            ['sku' => $product->sku, 'notes' => null],
        );

        $this->errorMessage = null;
        $this->dispatch('focus-search');
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
        $available = $this->availableQty($product);

        if ((int) $validated['qty'] > $available) {
            $this->addError('qty', "Only {$available} units available.");

            return;
        }

        $this->getCart()->add(
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
        $this->dispatch('focus-search');
    }

    public function incrementItem(string $rowId): void
    {
        if (!class_exists(\Centrex\Cart\Cart::class)) {
            return;
        }

        $cart = $this->getCart();
        $item = $cart->content()->get($rowId);

        if (!$item) {
            return;
        }

        $product = Product::find((int) $item->id);

        if ($product) {
            $available = $this->availableQty($product);

            if ($item->qty >= $available) {
                $this->errorMessage = "Max stock reached for {$product->name} ({$available} available).";
                $this->dispatch('focus-search');

                return;
            }
        }

        $cart->update($rowId, $item->qty + 1);
        $this->errorMessage = null;
        $this->dispatch('focus-search');
    }

    public function decrementItem(string $rowId): void
    {
        if (!class_exists(\Centrex\Cart\Cart::class)) {
            return;
        }

        $cart = $this->getCart();
        $item = $cart->content()->get($rowId);

        if ($item) {
            if ($item->qty <= 1) {
                $cart->remove($rowId);
            } else {
                $cart->update($rowId, $item->qty - 1);
            }
        }

        $this->dispatch('focus-search');
    }

    public function removeItem(string $rowId): void
    {
        if (!class_exists(\Centrex\Cart\Cart::class)) {
            return;
        }

        $this->getCart()->remove($rowId);
        $this->dispatch('focus-search');
    }

    public function clearCart(): void
    {
        if (!class_exists(\Centrex\Cart\Cart::class)) {
            return;
        }

        $this->getCart()->clear();
        $this->dispatch('focus-search');
    }

    // ── Checkout ────────────────────────────────────────────────────────────

    public function checkout(): void
    {
        $this->validate([
            'warehouse_id'    => ['required', 'integer'],
            'price_tier_code' => ['required', 'string'],
            'currency'        => ['required', 'string', 'size:3'],
        ]);

        if (!class_exists(\Centrex\Cart\Services\CartCheckoutService::class)) {
            $this->errorMessage = 'centrex/laravel-cart is required for POS checkout.';

            return;
        }

        $customerId = $this->tabCustomers[$this->activeTabId] ?? null;
        $couponCode = $this->tabCouponCodes[$this->activeTabId] ?? null;

        $saleOrder = app(\Centrex\Cart\Services\CartCheckoutService::class)->checkout([
            'warehouse_id'    => (int) $this->warehouse_id,
            'customer_id'     => $customerId ? (int) $customerId : null,
            'price_tier_code' => $this->price_tier_code,
            'currency'        => $this->currency,
            'coupon_code'     => $couponCode,
            'cart_instance'   => $this->cartInstance(),
            'confirm'         => true,
            'reserve'         => true,
            'fulfill'         => true,
            'clear_cart'      => true,
            'source'          => 'pos-terminal',
            'context'         => ['ui' => 'livewire', 'tab' => $this->activeTabId],
        ], 'pos');

        $this->tabCustomers[$this->activeTabId] = null;
        $this->tabCouponCodes[$this->activeTabId] = '';
        $this->dispatch('notify', type: 'success', message: "Sale {$saleOrder->so_number} completed!");
        $this->dispatch('focus-search');
    }

    // ── Render ──────────────────────────────────────────────────────────────

    public function render(): View
    {
        $cart = class_exists(\Centrex\Cart\Cart::class)
            ? $this->getCart()
            : null;

        $search = $this->tabSearches[$this->activeTabId] ?? '';

        $products = Product::query()
            ->with([
                'media',
                'warehouseProducts' => fn ($q) => $q->where('warehouse_id', $this->warehouse_id),
                // Only load prices matching current tier + effective today for this warehouse or global
                'prices' => fn ($q) => $q
                    ->where('price_tier_code', $this->price_tier_code)
                    ->where('is_active', true)
                    ->where(fn ($p) => $p->whereNull('effective_from')->orWhere('effective_from', '<=', today()))
                    ->where(fn ($p) => $p->whereNull('effective_to')->orWhere('effective_to', '>=', today()))
                    ->where(fn ($p) => $p->whereNull('warehouse_id')->orWhere('warehouse_id', $this->warehouse_id)),
            ])
            ->where('is_active', true)
            // Always show only in-stock: non-stockable always pass; stockable need available qty > 0
            ->where(fn ($q) => $q
                ->where('is_stockable', false)
                ->when(
                    $this->warehouse_id,
                    fn ($q2) => $q2->orWhereHas(
                        'warehouseProducts',
                        fn ($wp) => $wp
                            ->where('warehouse_id', $this->warehouse_id)
                            ->whereRaw('qty_on_hand - qty_reserved > 0'),
                    ),
                ),
            )
            ->when(
                $search,
                fn ($q) => $q->where(
                    fn ($inner) => $inner
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%"),
                ),
            )
            ->orderBy('name')
            ->get();

        $tabCartCounts = [];

        if (class_exists(\Centrex\Cart\Cart::class)) {
            foreach ($this->tabIds as $tabId) {
                $tabCartCounts[$tabId] = app(\Centrex\Cart\Cart::class)
                    ->instance('pos_tab_' . $tabId)
                    ->count();
            }
        }

        return view('inventory::livewire.transactions.pos-terminal', [
            'warehouses'    => Warehouse::query()->orderBy('name')->get(),
            'customers'     => Customer::query()->orderBy('name')->get(),
            'products'      => $products,
            'priceTiers'    => PriceTierCode::options(),
            'cartItems'     => $cart?->content() ?? collect(),
            'cartCount'     => $cart?->count() ?? 0,
            'cartTotal'     => $cart?->total() ?? 0,
            'tabCartCounts' => $tabCartCounts,
        ]);
    }
}

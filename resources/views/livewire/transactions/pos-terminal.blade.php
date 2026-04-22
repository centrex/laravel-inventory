<div
    class="flex flex-col overflow-hidden bg-base-100"
    style="height: 100dvh"
    x-data="{ fullscreen: false }"
    @fullscreenchange.window="fullscreen = !!document.fullscreenElement"
>
    <x-tallui-notification />

    {{-- ── TOP TOOLBAR ── --}}
    <div class="flex items-center gap-2 px-3 py-2 bg-base-200 border-b border-base-300 flex-shrink-0 flex-wrap gap-y-2">

        {{-- Brand --}}
        <div class="flex items-center gap-2 mr-2 flex-shrink-0">
            <x-heroicon-o-device-phone-mobile class="w-6 h-6 text-primary" />
            <span class="font-bold text-base hidden sm:block leading-none">POS Terminal</span>
        </div>

        {{-- Session controls --}}
        <div class="flex items-center gap-2 flex-1 flex-wrap gap-y-1 min-w-0">
            <select wire:model="warehouse_id" class="select select-sm select-bordered flex-1 min-w-28">
                <option value="">Warehouse…</option>
                @foreach ($warehouses as $w)
                    <option value="{{ $w->id }}">{{ $w->name }}</option>
                @endforeach
            </select>

            <select wire:model="customer_id" class="select select-sm select-bordered flex-1 min-w-28">
                <option value="">Walk-in</option>
                @foreach ($customers as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
            </select>

            <select wire:model="price_tier_code" class="select select-sm select-bordered flex-1 min-w-28">
                @foreach ($priceTiers as $tier)
                    <option value="{{ $tier['code'] }}">{{ $tier['name'] }}</option>
                @endforeach
            </select>

            <input wire:model="currency" class="input input-sm input-bordered w-16 text-center font-mono uppercase" placeholder="BDT" maxlength="3" />
        </div>

        {{-- Fullscreen toggle --}}
        <button
            class="btn btn-ghost btn-sm btn-square flex-shrink-0"
            title="Toggle fullscreen"
            @click="document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen()"
        >
            <x-heroicon-o-arrows-pointing-out x-show="!fullscreen" class="w-5 h-5" />
            <x-heroicon-o-arrows-pointing-in x-show="fullscreen" class="w-5 h-5" style="display:none" />
        </button>
    </div>

    @if ($errorMessage)
        <div class="px-4 pt-2 flex-shrink-0">
            <x-tallui-alert type="error" title="POS error" :dismissible="true">{{ $errorMessage }}</x-tallui-alert>
        </div>
    @endif

    {{-- ── MAIN AREA ── --}}
    <div class="flex flex-1 overflow-hidden flex-col md:flex-row">

        {{-- ═══ PRODUCT PANEL (left / top) ═══ --}}
        <div class="flex flex-col flex-1 overflow-hidden">

            {{-- Search bar --}}
            <div class="px-3 py-2 border-b border-base-200 flex-shrink-0">
                <label class="input input-sm input-bordered flex items-center gap-2 w-full">
                    <x-heroicon-o-magnifying-glass class="w-4 h-4 text-base-content/40 flex-shrink-0" />
                    <input
                        type="search"
                        wire:model.live.debounce.250ms="search"
                        placeholder="Search by name or SKU…"
                        class="grow bg-transparent outline-none"
                    />
                    @if ($search)
                        <button wire:click="$set('search', '')" class="flex-shrink-0 opacity-50 hover:opacity-100">
                            <x-heroicon-o-x-mark class="w-4 h-4" />
                        </button>
                    @endif
                </label>
            </div>

            {{-- Product grid --}}
            <div class="flex-1 overflow-y-auto p-3">
                @if ($products->isEmpty())
                    <div class="flex flex-col items-center justify-center py-16 text-base-content/30 select-none">
                        <x-heroicon-o-archive-box-x-mark class="w-16 h-16 mb-3" />
                        <p class="text-base font-medium">No products found</p>
                        @if ($search)
                            <p class="text-sm mt-1">Try a different search term</p>
                        @endif
                    </div>
                @else
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
                        @foreach ($products as $product)
                            <button
                                wire:click="tapProduct({{ $product->id }})"
                                wire:key="product-{{ $product->id }}"
                                class="card card-compact bg-base-200 hover:bg-base-300 active:scale-95 transition-all text-left overflow-hidden touch-manipulation select-none border border-transparent hover:border-primary/30 focus:outline-none focus:border-primary rounded-xl"
                            >
                                {{-- Image --}}
                                <figure class="aspect-square bg-base-300 overflow-hidden">
                                    @if ($product->primary_image_url)
                                        <img
                                            src="{{ $product->primary_image_url }}"
                                            alt="{{ $product->name }}"
                                            class="w-full h-full object-cover"
                                            loading="lazy"
                                        />
                                    @else
                                        <div class="w-full h-full flex items-center justify-center">
                                            <x-heroicon-o-photo class="w-10 h-10 text-base-content/15" />
                                        </div>
                                    @endif
                                </figure>

                                {{-- Info --}}
                                <div class="p-2 space-y-0.5">
                                    <p class="text-sm font-semibold leading-tight line-clamp-2">{{ $product->name }}</p>
                                    <p class="text-xs text-base-content/40 font-mono">{{ $product->sku }}</p>
                                    @if (!empty($product->meta['default_price']))
                                        <p class="text-sm font-bold text-primary font-mono pt-0.5">
                                            {{ $currency }} {{ number_format((float) $product->meta['default_price'], 2) }}
                                        </p>
                                    @endif
                                    @if ($product->is_stockable)
                                        @php $stock = $product->warehouseProducts->first(); $qty = $stock?->qtyAvailable() ?? 0; @endphp
                                        <p @class([
                                            'text-xs font-mono mt-0.5',
                                            'text-success'  => $qty > 10,
                                            'text-warning'  => $qty > 0 && $qty <= 10,
                                        ])>
                                            {{ number_format($qty, 0) }} {{ $product->unit ?? 'pcs' }} left
                                        </p>
                                    @endif
                                </div>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- ═══ CART PANEL (right / bottom) ═══ --}}
        <div class="flex flex-col border-t md:border-t-0 md:border-l border-base-200 bg-base-50 md:w-80 lg:w-96 flex-shrink-0 max-h-[45vh] md:max-h-none">

            {{-- Cart header --}}
            <div class="flex items-center justify-between px-4 py-3 border-b border-base-200 flex-shrink-0 bg-base-200">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-shopping-cart class="w-5 h-5" />
                    <span class="font-semibold">Cart</span>
                    @if ($cartCount > 0)
                        <span class="badge badge-primary badge-sm">{{ $cartCount }}</span>
                    @endif
                </div>
                @if ($cartCount > 0)
                    <button
                        wire:click="clearCart"
                        wire:confirm="Clear all items from the cart?"
                        class="btn btn-ghost btn-xs text-error touch-manipulation"
                    >
                        Clear all
                    </button>
                @endif
            </div>

            {{-- Cart items --}}
            <div class="flex-1 overflow-y-auto divide-y divide-base-200">
                @forelse ($cartItems as $item)
                    <div class="flex items-center gap-2 px-3 py-3" wire:key="cart-{{ $item->rowId }}">
                        {{-- Name + price --}}
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium leading-tight truncate">{{ $item->name }}</p>
                            <p class="text-xs text-base-content/40 font-mono">@ {{ number_format($item->price, 2) }}</p>
                        </div>

                        {{-- Qty stepper --}}
                        <div class="flex items-center gap-0.5 flex-shrink-0">
                            <button
                                wire:click="decrementItem('{{ $item->rowId }}')"
                                class="btn btn-sm btn-ghost btn-square touch-manipulation text-lg font-bold leading-none"
                            >−</button>
                            <span class="text-sm font-mono w-7 text-center select-none tabular-nums">{{ $item->qty }}</span>
                            <button
                                wire:click="incrementItem('{{ $item->rowId }}')"
                                class="btn btn-sm btn-ghost btn-square touch-manipulation text-lg font-bold leading-none"
                            >+</button>
                        </div>

                        {{-- Subtotal + remove --}}
                        <div class="flex items-center gap-1 flex-shrink-0">
                            <span class="text-sm font-mono font-semibold w-20 text-right tabular-nums">{{ number_format($item->subtotal, 2) }}</span>
                            <button
                                wire:click="removeItem('{{ $item->rowId }}')"
                                class="btn btn-sm btn-ghost btn-square text-error touch-manipulation flex-shrink-0"
                            >
                                <x-heroicon-o-x-mark class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="flex flex-col items-center justify-center py-10 text-base-content/30 select-none">
                        <x-heroicon-o-shopping-cart class="w-12 h-12 mb-2" />
                        <p class="text-sm font-medium">Cart is empty</p>
                        <p class="text-xs mt-0.5">Tap a product to add</p>
                    </div>
                @endforelse
            </div>

            {{-- Cart footer --}}
            <div class="border-t border-base-200 flex-shrink-0 bg-base-100">
                {{-- Total row --}}
                <div class="flex items-baseline justify-between px-4 py-3 border-b border-base-200">
                    <span class="text-xs text-base-content/50 uppercase tracking-widest font-medium">Total</span>
                    <span class="text-2xl font-bold font-mono text-primary tabular-nums">
                        {{ $currency }} {{ number_format($cartTotal, 2) }}
                    </span>
                </div>

                {{-- Checkout button --}}
                <div class="p-3">
                    <button
                        wire:click="checkout"
                        wire:confirm="Complete this sale and post to inventory?"
                        wire:loading.attr="disabled"
                        wire:target="checkout"
                        @class([
                            'btn btn-lg w-full gap-2 touch-manipulation',
                            'btn-primary' => $cartCount > 0,
                            'btn-disabled opacity-40 cursor-not-allowed' => $cartCount === 0,
                        ])
                        @disabled($cartCount === 0)
                    >
                        <span wire:loading.remove wire:target="checkout" class="flex items-center gap-2">
                            <x-heroicon-o-check-badge class="w-5 h-5" />
                            Checkout &amp; Fulfill
                        </span>
                        <span wire:loading wire:target="checkout" class="flex items-center gap-2">
                            <span class="loading loading-spinner loading-sm"></span>
                            Processing…
                        </span>
                    </button>
                </div>
            </div>
        </div>

    </div>
</div>

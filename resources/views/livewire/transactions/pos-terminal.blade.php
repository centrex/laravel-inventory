<div
    class="flex flex-col overflow-hidden bg-base-100"
    style="height: 100dvh"
    x-data="{
        fullscreen: false,
        focusSearch() { $nextTick(() => { const el = document.getElementById('pos-search'); if (el) el.focus(); }); }
    }"
    @fullscreenchange.window="fullscreen = !!document.fullscreenElement"
    @focus-search.window="focusSearch()"
    x-init="focusSearch()"
>
    <x-tallui-notification />

    {{-- ── CONFIRMATION DIALOGS ── --}}
    <x-tallui-dialog id="pos-confirm-clear" type="warning" title="Clear cart?">
        All items in this tab will be removed.
        <x-slot:footer>
            <button @click="open = false" class="btn btn-ghost">Cancel</button>
            <button wire:click="clearCart" @click="open = false" class="btn btn-error">Clear all</button>
        </x-slot:footer>
    </x-tallui-dialog>

    <x-tallui-dialog id="pos-confirm-checkout" type="confirm" title="Complete sale?">
        This will post the order to inventory and fulfill stock immediately.
        <x-slot:footer>
            <button @click="open = false" class="btn btn-ghost">Cancel</button>
            <button
                wire:click="checkout"
                wire:loading.attr="disabled"
                wire:target="checkout"
                @click="open = false"
                class="btn btn-primary gap-2"
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
        </x-slot:footer>
    </x-tallui-dialog>

    {{-- ── TOP TOOLBAR ── --}}
    <div class="flex items-center gap-2 px-3 py-2 bg-base-200 border-b border-base-300 flex-shrink-0 flex-wrap gap-y-2">

        {{-- Brand --}}
        <div class="flex items-center gap-2 mr-2 flex-shrink-0">
            <x-heroicon-o-device-phone-mobile class="w-6 h-6 text-primary" />
            <span class="font-bold text-base hidden sm:block leading-none">POS Terminal</span>
        </div>

        {{-- Session controls (warehouse / tier / currency — shared across tabs) --}}
        <div class="flex items-center gap-2 flex-1 flex-wrap gap-y-1 min-w-0">
            <select wire:model.live="warehouse_id" class="select select-sm select-bordered flex-1 min-w-28">
                <option value="">Warehouse…</option>
                @foreach ($warehouses as $w)
                    <option value="{{ $w->id }}">{{ $w->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="price_tier_code" class="select select-sm select-bordered flex-1 min-w-28">
                @foreach ($priceTiers as $tier)
                    <option value="{{ $tier['code'] }}">{{ $tier['name'] }}</option>
                @endforeach
            </select>

            <input wire:model.blur="currency" class="input input-sm input-bordered w-16 text-center font-mono uppercase" placeholder="BDT" maxlength="3" />
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

    {{-- ── TAB STRIP ── --}}
    <div class="flex items-center gap-1 px-2 py-1.5 bg-base-300 border-b border-base-300 flex-shrink-0 overflow-x-auto">
        @foreach ($tabIds as $tabId)
            <button
                wire:click="switchTab({{ $tabId }})"
                wire:key="tab-btn-{{ $tabId }}"
                @class([
                    'group flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium transition-all whitespace-nowrap',
                    'bg-base-100 shadow text-base-content border border-base-200' => $activeTabId === $tabId,
                    'text-base-content/60 hover:bg-base-200 hover:text-base-content' => $activeTabId !== $tabId,
                ])
            >
                <span>{{ $tabLabels[$tabId] }}</span>
                @if (($tabCartCounts[$tabId] ?? 0) > 0)
                    <span @class([
                        'badge badge-xs tabular-nums',
                        'badge-primary' => $activeTabId === $tabId,
                        'badge-ghost'   => $activeTabId !== $tabId,
                    ])>{{ $tabCartCounts[$tabId] }}</span>
                @endif
                @if (count($tabIds) > 1)
                    <span
                        wire:click.stop="closeTab({{ $tabId }})"
                        class="opacity-0 group-hover:opacity-60 hover:!opacity-100 text-xs leading-none cursor-pointer ml-0.5"
                        title="Close tab"
                    >×</span>
                @endif
            </button>
        @endforeach

        @if (count($tabIds) < 5)
            <button
                wire:click="addTab"
                class="flex items-center gap-1 px-2 py-1.5 rounded-lg text-sm text-base-content/50 hover:text-base-content hover:bg-base-200 transition-all flex-shrink-0"
                title="New tab (serve another customer)"
            >
                <x-heroicon-o-plus class="w-4 h-4" />
            </button>
        @endif
    </div>

    @if ($errorMessage)
        <div class="px-4 pt-2 flex-shrink-0">
            <x-tallui-alert type="error" title="POS error" :dismissible="true">{{ $errorMessage }}</x-tallui-alert>
        </div>
    @endif

    {{-- ── MAIN AREA ── --}}
    <div class="flex flex-1 overflow-hidden flex-row">

        {{-- ═══ PRODUCT PANEL (left / top) ═══ --}}
        <div class="flex flex-col flex-1 min-w-0 overflow-hidden">

            {{-- Barcode / search bar — always focused for scanner input --}}
            <div class="px-3 py-2 border-b border-base-200 flex-shrink-0">
                <label class="input input-sm input-bordered flex items-center gap-2 w-full">
                    <x-heroicon-o-qr-code class="w-4 h-4 text-base-content/40 flex-shrink-0" />
                    <input
                        id="pos-search"
                        type="search"
                        wire:model.live.debounce.250ms="tabSearches.{{ $activeTabId }}"
                        placeholder="Scan barcode or search by name / SKU…"
                        class="grow bg-transparent outline-none"
                        autocomplete="off"
                        @keydown.enter.prevent="$wire.scanFromSearch()"
                    />
                    @if (!empty($tabSearches[$activeTabId]))
                        <button wire:click="clearSearch" class="flex-shrink-0 opacity-50 hover:opacity-100">
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
                        @if (!empty($tabSearches[$activeTabId]))
                            <p class="text-sm mt-1">Try a different search term or barcode</p>
                        @endif
                    </div>
                @else
                    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 2xl:grid-cols-6 gap-3">
                        @foreach ($products as $product)
                            <button
                                wire:click="tapProduct({{ $product->id }})"
                                wire:key="product-{{ $product->id }}"
                                class="card card-compact bg-base-200 hover:bg-base-300 active:scale-95 transition-all text-left overflow-hidden touch-manipulation select-none border border-transparent hover:border-primary/30 focus:outline-none focus:border-primary rounded-xl max-w-sm"
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
                                <div class="p-2.5 space-y-1">
                                    <p class="text-sm font-semibold leading-snug line-clamp-2">{{ $product->name }}</p>
                                    <p class="text-xs text-base-content/40 font-mono">{{ $product->sku }}</p>
                                    @php
                                        // Warehouse-specific tier price → global tier price → meta fallback
                                        $tierPrice = $product->prices->first(fn($p) => $p->warehouse_id == $warehouse_id)
                                            ?? $product->prices->first();
                                        $displayPrice = $tierPrice
                                            ? (float) $tierPrice->price_local
                                            : (float) ($product->meta['default_price'] ?? 0);
                                    @endphp
                                    @if ($displayPrice > 0)
                                        <p class="text-sm font-bold text-primary font-mono pt-1">
                                            {{ $currency }} {{ number_format($displayPrice, 2) }}
                                        </p>
                                    @endif
                                    @if ($product->is_stockable)
                                        @php $stock = $product->warehouseProducts->first(); $qty = $stock?->qtyAvailable() ?? 0; @endphp
                                        <p @class([
                                            'text-xs font-mono pt-0.5',
                                            'text-success' => $qty > 10,
                                            'text-warning' => $qty > 0 && $qty <= 10,
                                        ])>
                                            {{ number_format($qty, 0) }} {{ $product->unit ?? 'pcs' }} left
                                        </p>
                                    @endif
                                </div>
                            </button>
                        @endforeach
                    </div>

                    @if ($hasMoreProducts)
                        <div
                            wire:key="pos-product-load-more-{{ $productLimit }}"
                            x-intersect="$wire.loadMoreProducts()"
                            class="flex items-center justify-center py-5"
                        >
                            <span wire:loading wire:target="loadMoreProducts" class="loading loading-spinner loading-sm text-primary"></span>
                        </div>
                    @endif
                @endif
            </div>
        </div>

        {{-- ═══ CART PANEL (right / bottom) ═══ --}}
        <div class="flex flex-col border-l border-base-200 bg-base-50 w-80 lg:w-96 flex-shrink-0">

            {{-- Cart header + per-tab customer --}}
            <div class="px-4 py-3 border-b border-base-200 flex-shrink-0 bg-base-200 space-y-2">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-shopping-cart class="w-5 h-5" />
                        <span class="font-semibold">Cart</span>
                        @if ($cartCount > 0)
                            <span class="badge badge-primary badge-sm">{{ $cartCount }}</span>
                        @endif
                    </div>
                    @if ($cartCount > 0)
                        <button
                            @click="$dispatch('open-dialog', 'pos-confirm-clear')"
                            class="btn btn-ghost btn-xs text-error touch-manipulation"
                        >
                            Clear all
                        </button>
                    @endif
                </div>

                {{-- Customer — per tab --}}
                <select wire:model.live="tabCustomers.{{ $activeTabId }}" class="select select-sm select-bordered w-full">
                    <option value="">Walk-in customer</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>

                <div class="flex items-center gap-2">
                    <input
                        wire:model="tabCouponInputs.{{ $activeTabId }}"
                        wire:keydown.enter="applyCoupon"
                        class="input input-sm input-bordered w-full font-mono uppercase"
                        placeholder="Coupon code"
                        maxlength="50"
                    />
                    <button
                        type="button"
                        wire:click="applyCoupon"
                        wire:loading.attr="disabled"
                        wire:target="applyCoupon"
                        class="btn btn-sm btn-outline flex-shrink-0"
                    >
                        <span wire:loading.remove wire:target="applyCoupon">Apply</span>
                        <span wire:loading wire:target="applyCoupon" class="loading loading-spinner loading-xs"></span>
                    </button>
                </div>

                @if ($couponPreview)
                    <div @class([
                        'flex items-center justify-between rounded-lg px-3 py-2 text-xs',
                        'bg-success/10 text-success' => empty($couponPreview['invalid']),
                        'bg-error/10 text-error' => !empty($couponPreview['invalid']),
                    ])>
                        <span class="font-medium truncate">
                            {{ $couponPreview['code'] }}
                            @if (!empty($couponPreview['invalid']))
                                · {{ $couponPreview['invalid'] }}
                            @else
                                applied
                            @endif
                        </span>
                        @if (empty($couponPreview['invalid']))
                            <span class="font-mono flex-shrink-0">-{{ $currency }} {{ number_format((float) $couponPreview['discount'], 2) }}</span>
                        @endif
                    </div>
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
                        <p class="text-xs mt-0.5">Tap a product or scan a barcode</p>
                    </div>
                @endforelse
            </div>

            {{-- Cart footer --}}
            <div class="border-t border-base-200 flex-shrink-0 bg-base-100">
                @php
                    $couponDiscountLocal = $couponPreview && empty($couponPreview['invalid'])
                        ? (float) $couponPreview['discount']
                        : 0.0;
                    $payableTotal = max(0, (float) $cartTotal - $couponDiscountLocal);
                @endphp

                @if ($couponDiscountLocal > 0)
                    <div class="flex items-center justify-between px-4 py-2 border-b border-base-200 text-sm">
                        <span class="text-base-content/50 font-medium">Coupon</span>
                        <span class="font-mono text-success tabular-nums">-{{ $currency }} {{ number_format($couponDiscountLocal, 2) }}</span>
                    </div>
                @endif

                {{-- Total row --}}
                <div class="flex items-baseline justify-between px-4 py-3 border-b border-base-200">
                    <span class="text-xs text-base-content/50 uppercase tracking-widest font-medium">Total</span>
                    <span class="text-2xl font-bold font-mono text-primary tabular-nums">
                        {{ $currency }} {{ number_format($payableTotal, 2) }}
                    </span>
                </div>

                {{-- Checkout button --}}
                <div class="p-3">
                    <button
                        wire:loading.attr="disabled"
                        wire:target="checkout"
                        @class([
                            'btn btn-lg w-full gap-2 touch-manipulation',
                            'btn-primary' => $cartCount > 0,
                            'btn-disabled opacity-40 cursor-not-allowed' => $cartCount === 0,
                        ])
                        @disabled($cartCount === 0)
                        @click="if (!$el.disabled) $dispatch('open-dialog', 'pos-confirm-checkout')"
                    >
                        <x-heroicon-o-check-badge class="w-5 h-5" />
                        Checkout &amp; Fulfill
                    </button>
                </div>
            </div>
        </div>

    </div>
</div>

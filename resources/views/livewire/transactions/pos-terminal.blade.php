<div>
<x-tallui-notification />

<x-tallui-page-header
    title="POS Terminal"
    subtitle="Ring up counter sales, then post directly into inventory and accounting."
    icon="o-device-phone-mobile"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'POS Terminal'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-badge type="secondary">POS</x-tallui-badge>
    </x-slot:actions>
</x-tallui-page-header>

@if ($errorMessage)
    <x-tallui-alert type="error" title="POS unavailable" class="mb-4">{{ $errorMessage }}</x-tallui-alert>
@endif

<div class="grid grid-cols-1 lg:grid-cols-5 gap-4">

    {{-- Left: Add items panel --}}
    <div class="lg:col-span-3 space-y-4">
        <x-tallui-card title="Add Item" subtitle="Configure session and select a product to add." icon="o-plus-circle" :shadow="true">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-tallui-form-group label="Warehouse">
                    <x-tallui-select name="warehouse_id" wire:model="warehouse_id">
                        <option value="">Select warehouse…</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                        @endforeach
                    </x-tallui-select>
                </x-tallui-form-group>

                <x-tallui-form-group label="Customer">
                    <x-tallui-select name="customer_id" wire:model="customer_id">
                        <option value="">Walk-in customer</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                        @endforeach
                    </x-tallui-select>
                </x-tallui-form-group>

                <x-tallui-form-group label="Price Tier">
                    <x-tallui-select name="price_tier_code" wire:model="price_tier_code">
                        @foreach ($priceTiers as $tier)
                            <option value="{{ $tier->code }}">{{ $tier->name }}</option>
                        @endforeach
                    </x-tallui-select>
                </x-tallui-form-group>

                <x-tallui-form-group label="Currency">
                    <x-tallui-input name="currency" wire:model="currency" placeholder="BDT" />
                </x-tallui-form-group>
            </div>

            <div class="divider my-3 text-xs text-base-content/40">Add Product</div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <x-tallui-form-group label="Product">
                        <x-tallui-select name="product_id" wire:model.live="product_id">
                            <option value="">Select product…</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}">{{ $product->name }} ({{ $product->sku }})</option>
                            @endforeach
                        </x-tallui-select>
                    </x-tallui-form-group>
                </div>

                <x-tallui-form-group label="Quantity">
                    <x-tallui-input name="qty" type="number" min="1" step="1" wire:model="qty" class="text-right" />
                </x-tallui-form-group>

                <x-tallui-form-group label="Unit Price (Local)">
                    <x-tallui-input name="unit_price_local" type="number" step="0.0001" min="0" wire:model="unit_price_local" class="text-right" />
                </x-tallui-form-group>

                <div class="md:col-span-2">
                    <x-tallui-form-group label="Line Notes">
                        <x-tallui-input name="notes" wire:model="notes" placeholder="Optional note for this item…" />
                    </x-tallui-form-group>
                </div>
            </div>

            <div class="flex justify-end mt-4">
                <x-tallui-button
                    label="Add to Cart"
                    icon="o-plus"
                    class="btn-primary"
                    type="button"
                    wire:click="addProduct"
                    :spinner="'addProduct'"
                />
            </div>
        </x-tallui-card>
    </div>

    {{-- Right: Basket panel --}}
    <div class="lg:col-span-2 space-y-4">
        <x-tallui-card padding="none" :shadow="true">
            <x-slot:actions>
                <x-tallui-badge type="neutral">{{ $cartCount }} {{ $cartCount === 1 ? 'item' : 'items' }}</x-tallui-badge>
            </x-slot:actions>

            {{-- Cart total stat --}}
            <div class="px-5 pt-3 pb-2">
                <div class="flex items-end justify-between">
                    <div>
                        <p class="text-xs text-base-content/50 uppercase tracking-wide">Cart Total</p>
                        <p class="text-3xl font-bold font-mono text-primary">{{ number_format($cartTotal, 2) }}</p>
                    </div>
                    <x-heroicon-o-shopping-cart class="w-10 h-10 text-base-content/10" />
                </div>
            </div>

            <div class="border-t border-base-200">
                <div class="overflow-x-auto">
                    <table class="table table-sm w-full">
                        <thead>
                            <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                                <th class="pl-5">Item</th>
                                <th class="text-right w-12">Qty</th>
                                <th class="text-right w-24">Price</th>
                                <th class="text-right w-24">Total</th>
                                <th class="pr-5 w-10"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-base-200">
                            @forelse ($cartItems as $item)
                                <tr class="hover:bg-base-50">
                                    <td class="pl-5 py-2 text-sm font-medium max-w-[120px] truncate" title="{{ $item->name }}">
                                        {{ $item->name }}
                                    </td>
                                    <td class="py-2 text-right text-sm font-mono">{{ $item->qty }}</td>
                                    <td class="py-2 text-right text-sm font-mono">{{ number_format($item->price, 2) }}</td>
                                    <td class="py-2 text-right text-sm font-mono font-semibold">{{ number_format($item->subtotal, 2) }}</td>
                                    <td class="pr-5 py-2 text-right">
                                        <x-tallui-button
                                            icon="o-x-mark"
                                            class="btn-ghost btn-xs text-error"
                                            type="button"
                                            wire:click="removeItem('{{ $item->rowId }}')"
                                        />
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-8 text-center text-sm text-base-content/40">
                                        Cart is empty. Add a product to start.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($cartCount > 0)
                <div class="px-5 py-4 border-t border-base-200">
                    <x-tallui-button
                        label="Checkout & Fulfill"
                        icon="o-check-badge"
                        class="btn-primary w-full"
                        type="button"
                        wire:click="checkout"
                        :spinner="'checkout'"
                        wire:confirm="Complete this sale and post to inventory?"
                    />
                </div>
            @endif
        </x-tallui-card>
    </div>

</div>
</div>

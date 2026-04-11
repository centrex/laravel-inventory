<div class="grid">
    <x-tallui-page-header title="POS Terminal" subtitle="Ring up counter sales using the cart engine, then post into inventory and accounting." icon="o-device-phone-mobile">
        <x-slot:actions>
            <x-tallui-badge color="outline">POS</x-tallui-badge>
        </x-slot:actions>
    </x-tallui-page-header>

    @if ($errorMessage)
        <x-tallui-alert type="error" title="POS unavailable">{{ $errorMessage }}</x-tallui-alert>
    @endif

    <div class="grid" style="grid-template-columns: 1.2fr .8fr;">
        <x-tallui-card title="Add Item" subtitle="Build the live POS basket." icon="o-plus-circle" :shadow="true">
            <div class="stack">
                <div class="form-grid">
                    <div>
                        <x-tallui-select name="warehouse_id" label="Warehouse" wire:model="warehouse_id">
                            <option value="">Select warehouse</option>
                            @foreach ($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                            @endforeach
                        </x-tallui-select>
                    </div>
                    <div>
                        <x-tallui-select name="customer_id" label="Customer" wire:model="customer_id">
                            <option value="">Walk-in customer</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                            @endforeach
                        </x-tallui-select>
                    </div>
                    <div>
                        <x-tallui-select name="price_tier_code" label="Price Tier" wire:model="price_tier_code">
                            @foreach ($priceTiers as $tier)
                                <option value="{{ $tier->code }}">{{ $tier->name }}</option>
                            @endforeach
                        </x-tallui-select>
                    </div>
                    <div><x-tallui-input name="currency" label="Currency" wire:model="currency" /></div>
                    <div class="span-2">
                        <x-tallui-select name="product_id" label="Product" wire:model.live="product_id">
                            <option value="">Select product</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}">{{ $product->name }} ({{ $product->sku }})</option>
                            @endforeach
                        </x-tallui-select>
                    </div>
                    <div><x-tallui-input name="qty" label="Qty" type="number" min="1" wire:model="qty" /></div>
                    <div><x-tallui-input name="unit_price_local" label="Unit Price" type="number" step="0.0001" wire:model="unit_price_local" /></div>
                    <div class="span-2"><x-tallui-input name="notes" label="Line Notes" wire:model="notes" /></div>
                </div>

                <div class="actions">
                    <x-tallui-button label="Add to POS Cart" icon="o-plus" class="btn-primary" type="button" wire:click="addProduct" />
                </div>
            </div>
        </x-tallui-card>

        <x-tallui-card title="Current Basket" subtitle="POS instance cart contents." icon="o-shopping-cart" :shadow="true">
            <div class="stack">
                <x-tallui-stat title="Items" value="{{ $cartCount }}" desc="Units in basket" icon="o-cube" />
                <x-tallui-stat title="Total" value="{{ number_format($cartTotal, 2) }}" desc="Cart total" icon="o-banknotes" />

                <div class="table-shell">
                    <table>
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Qty</th>
                                <th>Price</th>
                                <th>Total</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($cartItems as $item)
                                <tr>
                                    <td>{{ $item->name }}</td>
                                    <td>{{ $item->qty }}</td>
                                    <td>{{ number_format($item->price, 2) }}</td>
                                    <td>{{ number_format($item->subtotal, 2) }}</td>
                                    <td><x-tallui-button label="Remove" class="btn-ghost btn-sm" type="button" wire:click="removeItem('{{ $item->rowId }}')" /></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="muted">POS cart is empty.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="actions">
                    <x-tallui-button label="Checkout and Fulfill" icon="o-check-badge" class="btn-primary" type="button" wire:click="checkout" />
                </div>
            </div>
        </x-tallui-card>
    </div>
</div>

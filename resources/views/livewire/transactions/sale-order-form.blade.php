<div class="grid">
    <x-tallui-page-header title="Create Sale Order" subtitle="Capture outbound sales with tier-based or manual pricing." icon="o-shopping-cart">
        <x-slot:actions>
            <x-tallui-badge color="outline">Sales</x-tallui-badge>
        </x-slot:actions>
    </x-tallui-page-header>

    <x-tallui-card title="Sale Order" subtitle="Header and pricing details" icon="o-banknotes" :shadow="true">
        <form wire:submit="save" class="stack">
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
                        <option value="">Walk-in / none</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                        @endforeach
                    </x-tallui-select>
                </div>
                <div>
                    <x-tallui-select name="price_tier_code" label="Default Price Tier" wire:model="price_tier_code">
                        @foreach ($priceTiers as $tier)
                            <option value="{{ $tier->code }}">{{ $tier->name }}</option>
                        @endforeach
                    </x-tallui-select>
                </div>
                <div><x-tallui-input name="currency" label="Currency" wire:model="currency" /></div>
                <div><x-tallui-input name="exchange_rate" label="Exchange Rate BDT" type="number" step="0.0001" wire:model="exchange_rate" /></div>
                <div><x-tallui-input name="tax_local" label="Tax Local" type="number" step="0.0001" wire:model="tax_local" /></div>
                <div><x-tallui-input name="discount_local" label="Discount Local" type="number" step="0.0001" wire:model="discount_local" /></div>
                <div class="span-2"><x-tallui-textarea name="notes" label="Notes" wire:model="notes" /></div>
            </div>

            <h2 class="section-title">Items</h2>
            <div class="stack">
                <div class="table-shell">
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Qty</th>
                                <th>Tier Override</th>
                                <th>Unit Price Local</th>
                                <th>Discount %</th>
                                <th>Notes</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($items as $index => $item)
                                <tr wire:key="so-item-{{ $index }}">
                                    <td>
                                        <x-tallui-select name="items.{{ $index }}.product_id" label="" wire:model="items.{{ $index }}.product_id">
                                            <option value="">Select product</option>
                                            @foreach ($products as $product)
                                                <option value="{{ $product->id }}">{{ $product->name }}</option>
                                            @endforeach
                                        </x-tallui-select>
                                    </td>
                                    <td><x-tallui-input name="items.{{ $index }}.qty_ordered" label="" type="number" step="0.0001" wire:model="items.{{ $index }}.qty_ordered" /></td>
                                    <td>
                                        <x-tallui-select name="items.{{ $index }}.price_tier_code" label="" wire:model="items.{{ $index }}.price_tier_code">
                                            <option value="">Default</option>
                                            @foreach ($priceTiers as $tier)
                                                <option value="{{ $tier->code }}">{{ $tier->name }}</option>
                                            @endforeach
                                        </x-tallui-select>
                                    </td>
                                    <td><x-tallui-input name="items.{{ $index }}.unit_price_local" label="" type="number" step="0.0001" wire:model="items.{{ $index }}.unit_price_local" /></td>
                                    <td><x-tallui-input name="items.{{ $index }}.discount_pct" label="" type="number" step="0.01" wire:model="items.{{ $index }}.discount_pct" /></td>
                                    <td><x-tallui-input name="items.{{ $index }}.notes" label="" wire:model="items.{{ $index }}.notes" /></td>
                                    <td><x-tallui-button label="Remove" class="btn-ghost btn-sm" type="button" wire:click="removeItem({{ $index }})" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="actions"><x-tallui-button label="Add Line" icon="o-plus" class="btn-ghost btn-sm" type="button" wire:click="addItem" /></div>
            </div>

            <div class="actions"><x-tallui-button label="Create Sale Order" icon="o-check" class="btn-primary" type="submit" /></div>
        </form>
    </x-tallui-card>
</div>

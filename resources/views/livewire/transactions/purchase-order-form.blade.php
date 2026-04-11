<div class="grid">
    <x-tallui-page-header title="Create Purchase Order" subtitle="Draft inbound purchases with multi-line item entry." icon="o-arrow-down-tray">
        <x-slot:actions>
            <x-tallui-badge color="outline">Purchasing</x-tallui-badge>
        </x-slot:actions>
    </x-tallui-page-header>

    <x-tallui-card title="Purchase Order" subtitle="Header and line details" icon="o-document-arrow-down" :shadow="true">
        <form wire:submit="save" class="stack">
            <div class="form-grid">
                <div>
                    <x-tallui-select name="warehouse_id" label="Warehouse" wire:model="warehouse_id">
                        <option value="">Select warehouse</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                        @endforeach
                    </x-tallui-select>
                    @error('warehouse_id') <div class="danger">{{ $message }}</div> @enderror
                </div>
                <div>
                    <x-tallui-select name="supplier_id" label="Supplier" wire:model="supplier_id">
                        <option value="">Select supplier</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </x-tallui-select>
                    @error('supplier_id') <div class="danger">{{ $message }}</div> @enderror
                </div>
                <div><x-tallui-input name="currency" label="Currency" wire:model="currency" />@error('currency') <div class="danger">{{ $message }}</div> @enderror</div>
                <div><x-tallui-input name="exchange_rate" label="Exchange Rate BDT" type="number" wire:model="exchange_rate" step="0.0001" />@error('exchange_rate') <div class="danger">{{ $message }}</div> @enderror</div>
                <div><x-tallui-input name="tax_local" label="Tax Local" type="number" wire:model="tax_local" step="0.0001" /></div>
                <div><x-tallui-input name="shipping_local" label="Shipping Local" type="number" wire:model="shipping_local" step="0.0001" /></div>
                <div><x-tallui-input name="other_charges_amount" label="Other Charges BDT" type="number" wire:model="other_charges_amount" step="0.0001" /></div>
                <div><x-tallui-input name="expected_at" label="Expected At" type="date" wire:model="expected_at" /></div>
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
                                <th>Unit Price Local</th>
                                <th>Notes</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($items as $index => $item)
                                <tr wire:key="po-item-{{ $index }}">
                                    <td>
                                        <x-tallui-select name="items.{{ $index }}.product_id" label="" wire:model="items.{{ $index }}.product_id">
                                            <option value="">Select product</option>
                                            @foreach ($products as $product)
                                                <option value="{{ $product->id }}">{{ $product->name }}</option>
                                            @endforeach
                                        </x-tallui-select>
                                    </td>
                                    <td><x-tallui-input name="items.{{ $index }}.qty_ordered" label="" type="number" step="0.0001" wire:model="items.{{ $index }}.qty_ordered" /></td>
                                    <td><x-tallui-input name="items.{{ $index }}.unit_price_local" label="" type="number" step="0.0001" wire:model="items.{{ $index }}.unit_price_local" /></td>
                                    <td><x-tallui-input name="items.{{ $index }}.notes" label="" wire:model="items.{{ $index }}.notes" /></td>
                                    <td><x-tallui-button label="Remove" class="btn-ghost btn-sm" type="button" wire:click="removeItem({{ $index }})" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="actions"><x-tallui-button label="Add Line" icon="o-plus" class="btn-ghost btn-sm" type="button" wire:click="addItem" /></div>
            </div>

            <div class="actions"><x-tallui-button label="Create Purchase Order" icon="o-check" class="btn-primary" type="submit" /></div>
        </form>
    </x-tallui-card>
</div>

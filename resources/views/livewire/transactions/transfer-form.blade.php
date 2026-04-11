<div class="grid">
    <x-tallui-page-header title="Create Transfer Shipment" subtitle="Prepare inter-warehouse shipments with landed-cost allocation." icon="o-arrows-right-left">
        <x-slot:actions>
            <x-tallui-badge color="outline">Transfers</x-tallui-badge>
        </x-slot:actions>
    </x-tallui-page-header>

    <x-tallui-card title="Transfer" subtitle="Source, destination, and shipment lines" icon="o-truck" :shadow="true">
        <form wire:submit="save" class="stack">
            <div class="form-grid">
                <div>
                    <x-tallui-select name="from_warehouse_id" label="From Warehouse" wire:model="from_warehouse_id">
                        <option value="">Select source</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                        @endforeach
                    </x-tallui-select>
                </div>
                <div>
                    <x-tallui-select name="to_warehouse_id" label="To Warehouse" wire:model="to_warehouse_id">
                        <option value="">Select destination</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                        @endforeach
                    </x-tallui-select>
                </div>
                <div><x-tallui-input name="shipping_rate_per_kg" label="Shipping Rate / KG BDT" type="number" step="0.0001" wire:model="shipping_rate_per_kg" /></div>
                <div class="span-2"><x-tallui-textarea name="notes" label="Notes" wire:model="notes" /></div>
            </div>

            <h2 class="section-title">Items</h2>
            <div class="stack">
                <div class="table-shell">
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Qty Sent</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($items as $index => $item)
                                <tr wire:key="trf-item-{{ $index }}">
                                    <td>
                                        <x-tallui-select name="items.{{ $index }}.product_id" label="" wire:model="items.{{ $index }}.product_id">
                                            <option value="">Select product</option>
                                            @foreach ($products as $product)
                                                <option value="{{ $product->id }}">{{ $product->name }}</option>
                                            @endforeach
                                        </x-tallui-select>
                                    </td>
                                    <td><x-tallui-input name="items.{{ $index }}.qty_sent" label="" type="number" step="0.0001" wire:model="items.{{ $index }}.qty_sent" /></td>
                                    <td><x-tallui-button label="Remove" class="btn-ghost btn-sm" type="button" wire:click="removeItem({{ $index }})" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="actions"><x-tallui-button label="Add Line" icon="o-plus" class="btn-ghost btn-sm" type="button" wire:click="addItem" /></div>
            </div>

            <div class="actions"><x-tallui-button label="Create Transfer" icon="o-check" class="btn-primary" type="submit" /></div>
        </form>
    </x-tallui-card>
</div>

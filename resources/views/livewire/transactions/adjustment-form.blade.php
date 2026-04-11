<div class="grid">
    <x-tallui-page-header title="Create Stock Adjustment" subtitle="Record count corrections, write-offs, and other stock corrections." icon="o-scale">
        <x-slot:actions>
            <x-tallui-badge color="outline">Adjustments</x-tallui-badge>
        </x-slot:actions>
    </x-tallui-page-header>

    <x-tallui-card title="Adjustment" subtitle="Warehouse and count variances" icon="o-clipboard-document-check" :shadow="true">
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
                    <x-tallui-select name="reason" label="Reason" wire:model="reason">
                        @foreach ($reasons as $reasonOption)
                            <option value="{{ $reasonOption->value }}">{{ $reasonOption->label() }}</option>
                        @endforeach
                    </x-tallui-select>
                </div>
                <div><x-tallui-input name="adjusted_at" label="Adjusted At" type="date" wire:model="adjusted_at" /></div>
                <div class="span-2"><x-tallui-textarea name="notes" label="Notes" wire:model="notes" /></div>
            </div>

            <h2 class="section-title">Items</h2>
            <div class="stack">
                <div class="table-shell">
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Actual Qty</th>
                                <th>Notes</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($items as $index => $item)
                                <tr wire:key="adj-item-{{ $index }}">
                                    <td>
                                        <x-tallui-select name="items.{{ $index }}.product_id" label="" wire:model="items.{{ $index }}.product_id">
                                            <option value="">Select product</option>
                                            @foreach ($products as $product)
                                                <option value="{{ $product->id }}">{{ $product->name }}</option>
                                            @endforeach
                                        </x-tallui-select>
                                    </td>
                                    <td><x-tallui-input name="items.{{ $index }}.qty_actual" label="" type="number" step="0.0001" wire:model="items.{{ $index }}.qty_actual" /></td>
                                    <td><x-tallui-input name="items.{{ $index }}.notes" label="" wire:model="items.{{ $index }}.notes" /></td>
                                    <td><x-tallui-button label="Remove" class="btn-ghost btn-sm" type="button" wire:click="removeItem({{ $index }})" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="actions"><x-tallui-button label="Add Line" icon="o-plus" class="btn-ghost btn-sm" type="button" wire:click="addItem" /></div>
            </div>

            <div class="actions"><x-tallui-button label="Create Adjustment" icon="o-check" class="btn-primary" type="submit" /></div>
        </form>
    </x-tallui-card>
</div>

<div>
<x-tallui-notification />

<x-tallui-page-header
    :title="$isEditing ? 'Edit ' . $documentLabel : 'New ' . $documentLabel"
    :subtitle="$documentLabel === 'Requisition' ? 'Capture internal purchasing demand before supplier confirmation.' : ($isEditing ? 'Review supplier lines, update draft details, and print or export purchase documents.' : 'Draft inbound purchases with multi-line item entry.')"
    icon="o-arrow-down-tray"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => $documentLabel === 'Requisition' ? 'Requisitions' : 'Purchase Orders', 'href' => route($routeBase . '.index')],
            ['label' => $isEditing ? 'Edit ' . $documentLabel : 'New ' . $documentLabel],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-badge type="info">Purchasing</x-tallui-badge>
        @if ($isEditing && $record && Route::has('erp.documents.purchases.print'))
            <x-tallui-button label="Print" icon="o-printer" :link="route('erp.documents.purchases.print', ['purchaseOrder' => $record->getKey()])" class="btn-ghost btn-sm" />
        @endif
        @if ($isEditing && $record && Route::has('erp.documents.purchases.pdf'))
            <x-tallui-button label="PDF" icon="o-arrow-down-tray" :link="route('erp.documents.purchases.pdf', ['purchaseOrder' => $record->getKey()])" class="btn-ghost btn-sm" />
        @endif
    </x-slot:actions>
</x-tallui-page-header>

<form wire:submit="save" class="space-y-4">

    {{-- Header --}}
    <x-tallui-card title="Order Details" subtitle="Supplier, warehouse, and cost settings." icon="o-document-arrow-down" :shadow="true">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <x-tallui-form-group label="Warehouse *" :error="$errors->first('warehouse_id')">
                <x-tallui-select name="warehouse_id" wire:model.live="warehouse_id" class="{{ $errors->has('warehouse_id') ? 'select-error' : '' }}">
                    <option value="">Select warehouse…</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>

            <x-tallui-form-group label="Supplier *" :error="$errors->first('supplier_id')">
                <x-tallui-select name="supplier_id" wire:model="supplier_id" class="{{ $errors->has('supplier_id') ? 'select-error' : '' }}">
                    <option value="">Select supplier…</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>

            <x-tallui-form-group label="Expected Date">
                <x-tallui-input name="expected_at" type="date" wire:model="expected_at" />
            </x-tallui-form-group>

            <x-tallui-form-group label="Currency" :error="$errors->first('currency')">
                <x-tallui-input name="currency" wire:model="currency" placeholder="BDT" />
            </x-tallui-form-group>

            <x-tallui-form-group label="Exchange Rate (BDT)" :error="$errors->first('exchange_rate')">
                <x-tallui-input name="exchange_rate" type="number" step="0.0001" wire:model="exchange_rate" />
            </x-tallui-form-group>

            <x-tallui-form-group label="Tax (Local)">
                <x-tallui-input name="tax_local" type="number" step="0.0001" wire:model="tax_local" placeholder="0.00" />
            </x-tallui-form-group>

            <x-tallui-form-group label="Shipping (Local)">
                <x-tallui-input name="shipping_local" type="number" step="0.0001" wire:model="shipping_local" placeholder="0.00" />
            </x-tallui-form-group>

            <x-tallui-form-group label="Other Charges (BDT)">
                <x-tallui-input name="other_charges_amount" type="number" step="0.0001" wire:model="other_charges_amount" placeholder="0.00" />
            </x-tallui-form-group>

            <div class="md:col-span-2 lg:col-span-3">
                <x-tallui-form-group label="Notes">
                    <x-tallui-textarea name="notes" wire:model="notes" rows="2" placeholder="Internal notes, delivery instructions…" />
                </x-tallui-form-group>
            </div>
        </div>
    </x-tallui-card>

    {{-- Line Items --}}
    <x-tallui-card padding="none" :shadow="true">
        <x-slot:actions>
            @if ($editable)
                <x-tallui-button
                    label="Add Line"
                    icon="o-plus"
                    class="btn-ghost btn-sm"
                    type="button"
                    wire:click="addItem"
                />
            @endif
        </x-slot:actions>

        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                        <th class="pl-5 w-64">Product</th>
                        <th class="w-28">Qty Ordered</th>
                        <th class="w-24">On Hand</th>
                        <th class="w-36">Unit Price (Local)</th>
                        <th>Notes</th>
                        <th class="pr-5 w-20"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-200">
                    @forelse ($items as $index => $item)
                        <tr wire:key="po-item-{{ $index }}" class="hover:bg-base-50">
                            <td class="pl-5 py-2">
                                <x-tallui-select
                                    name="items.{{ $index }}.product_id"
                                    wire:model.live="items.{{ $index }}.product_id"
                                    class="select-sm w-full"
                                >
                                    <option value="">Select product…</option>
                                    @foreach ($products as $product)
                                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                                    @endforeach
                                </x-tallui-select>
                            </td>
                            <td class="py-2">
                                <x-tallui-input
                                    name="items.{{ $index }}.qty_ordered"
                                    type="number" step="0.0001" min="0"
                                    wire:model="items.{{ $index }}.qty_ordered"
                                    class="input-sm text-right w-full"
                                />
                            </td>
                            <td class="py-2 text-sm text-base-content/70">
                                {{ number_format((float) ($onHandStock->get($item['product_id'] ?? 0)?->qty_on_hand ?? 0), 4) }}
                            </td>
                            <td class="py-2">
                                <x-tallui-input
                                    name="items.{{ $index }}.unit_price_local"
                                    type="number" step="0.0001" min="0"
                                    wire:model="items.{{ $index }}.unit_price_local"
                                    class="input-sm text-right w-full"
                                />
                            </td>
                            <td class="py-2">
                                <x-tallui-input
                                    name="items.{{ $index }}.notes"
                                    wire:model="items.{{ $index }}.notes"
                                    class="input-sm w-full"
                                    placeholder="Optional…"
                                />
                            </td>
                            <td class="pr-5 py-2 text-right">
                                @if ($editable)
                                    <x-tallui-button
                                        icon="o-trash"
                                        class="btn-ghost btn-xs text-error"
                                        type="button"
                                        wire:click="removeItem({{ $index }})"
                                    />
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-6 text-center">
                                <x-tallui-empty-state
                                    title="No items yet"
                                    description="Add at least one product line."
                                    icon="o-cube"
                                    size="sm"
                                >
                                    <x-tallui-button label="Add Line" icon="o-plus" class="btn-primary btn-sm" type="button" wire:click="addItem" />
                                </x-tallui-empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-tallui-card>

    {{-- Footer actions --}}
    <div class="flex justify-end gap-2">
        <x-tallui-button :label="$documentLabel === 'Requisition' ? 'Back to Requisitions' : 'Back to Purchases'" :link="route($routeBase . '.index')" class="btn-ghost" />
        @if ($editable)
            <x-tallui-button :label="$isEditing ? 'Update ' . $documentLabel : 'Create ' . $documentLabel" icon="o-check" class="btn-primary" type="submit" :spinner="'save'" />
        @endif
    </div>

</form>
</div>

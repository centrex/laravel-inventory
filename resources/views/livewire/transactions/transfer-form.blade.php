<div>
<x-tallui-notification />

<x-tallui-page-header
    title="New Transfer Shipment"
    subtitle="Prepare inter-warehouse shipments with landed-cost allocation."
    icon="o-arrows-right-left"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'New Transfer'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-badge type="info">Transfers</x-tallui-badge>
    </x-slot:actions>
</x-tallui-page-header>

<form wire:submit="save" class="space-y-4">

    {{-- Header --}}
    <x-tallui-card title="Transfer Details" subtitle="Source, destination, and shipment cost." icon="o-truck" :shadow="true">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <x-tallui-form-group label="From Warehouse *" :error="$errors->first('from_warehouse_id')">
                <x-tallui-select name="from_warehouse_id" wire:model="from_warehouse_id" class="{{ $errors->has('from_warehouse_id') ? 'select-error' : '' }}">
                    <option value="">Select source…</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>

            <x-tallui-form-group label="To Warehouse *" :error="$errors->first('to_warehouse_id')">
                <x-tallui-select name="to_warehouse_id" wire:model="to_warehouse_id" class="{{ $errors->has('to_warehouse_id') ? 'select-error' : '' }}">
                    <option value="">Select destination…</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>

            <x-tallui-form-group label="Shipping Rate / KG (BDT)">
                <x-tallui-input name="shipping_rate_per_kg" type="number" step="0.0001" min="0" wire:model="shipping_rate_per_kg" placeholder="0.00" />
            </x-tallui-form-group>

            <div class="md:col-span-2 lg:col-span-3">
                <x-tallui-form-group label="Notes">
                    <x-tallui-textarea name="notes" wire:model="notes" rows="2" placeholder="Shipment instructions, reference…" />
                </x-tallui-form-group>
            </div>
        </div>
    </x-tallui-card>

    {{-- Box Items --}}
    <x-tallui-card padding="none" :shadow="true">
        <x-slot:actions>
            <x-tallui-button label="Add Box" icon="o-plus" class="btn-ghost btn-sm" type="button" wire:click="addBox" />
        </x-slot:actions>

        <div class="space-y-4 p-4">
            @forelse ($boxes as $boxIndex => $box)
                <div wire:key="trf-box-{{ $boxIndex }}" class="rounded-2xl border border-base-200 bg-base-50">
                    <div class="grid grid-cols-1 gap-4 border-b border-base-200 p-4 md:grid-cols-3">
                        <x-tallui-form-group label="Box Reference" :error="$errors->first('boxes.' . $boxIndex . '.box_code')">
                            <x-tallui-input
                                name="boxes.{{ $boxIndex }}.box_code"
                                wire:model="boxes.{{ $boxIndex }}.box_code"
                                placeholder="BOX-001"
                            />
                        </x-tallui-form-group>

                        <x-tallui-form-group label="Measured Box Weight (KG) *" :error="$errors->first('boxes.' . $boxIndex . '.measured_weight_kg')">
                            <x-tallui-input
                                name="boxes.{{ $boxIndex }}.measured_weight_kg"
                                type="number"
                                step="0.0001"
                                min="0"
                                wire:model="boxes.{{ $boxIndex }}.measured_weight_kg"
                            />
                        </x-tallui-form-group>

                        <div class="flex items-end justify-end">
                            <x-tallui-button
                                label="Remove Box"
                                icon="o-trash"
                                class="btn-ghost btn-sm text-error"
                                type="button"
                                wire:click="removeBox({{ $boxIndex }})"
                            />
                        </div>

                        <div class="md:col-span-3">
                            <x-tallui-form-group label="Box Notes" :error="$errors->first('boxes.' . $boxIndex . '.notes')">
                                <x-tallui-input
                                    name="boxes.{{ $boxIndex }}.notes"
                                    wire:model="boxes.{{ $boxIndex }}.notes"
                                    placeholder="Box condition, route note, seal number…"
                                />
                            </x-tallui-form-group>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="table table-sm w-full">
                            <thead>
                                <tr class="bg-base-100 text-xs text-base-content/50 uppercase">
                                    <th class="pl-5">Product</th>
                                    <th class="w-32">Qty to Send</th>
                                    <th>Notes</th>
                                    <th class="pr-5 w-16"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-base-200">
                                @foreach ($box['items'] as $itemIndex => $item)
                                    <tr wire:key="trf-box-{{ $boxIndex }}-item-{{ $itemIndex }}">
                                        <td class="pl-5 py-2">
                                            <x-tallui-select
                                                name="boxes.{{ $boxIndex }}.items.{{ $itemIndex }}.product_id"
                                                wire:model="boxes.{{ $boxIndex }}.items.{{ $itemIndex }}.product_id"
                                                class="select-sm w-full max-w-sm"
                                            >
                                                <option value="">Select product…</option>
                                                @foreach ($products as $product)
                                                    <option value="{{ $product->id }}">
                                                        {{ $product->name }}@if($product->weight_kg !== null) · {{ number_format((float) $product->weight_kg, 4) }} kg/u @endif
                                                    </option>
                                                @endforeach
                                            </x-tallui-select>
                                        </td>
                                        <td class="py-2">
                                            <x-tallui-input
                                                name="boxes.{{ $boxIndex }}.items.{{ $itemIndex }}.qty_sent"
                                                type="number"
                                                step="0.0001"
                                                min="0"
                                                wire:model="boxes.{{ $boxIndex }}.items.{{ $itemIndex }}.qty_sent"
                                                class="input-sm text-right w-28"
                                            />
                                        </td>
                                        <td class="py-2">
                                            <x-tallui-input
                                                name="boxes.{{ $boxIndex }}.items.{{ $itemIndex }}.notes"
                                                wire:model="boxes.{{ $boxIndex }}.items.{{ $itemIndex }}.notes"
                                                class="input-sm w-full"
                                                placeholder="Optional…"
                                            />
                                        </td>
                                        <td class="pr-5 py-2 text-right">
                                            <x-tallui-button
                                                icon="o-trash"
                                                class="btn-ghost btn-xs text-error"
                                                type="button"
                                                wire:click="removeItem({{ $boxIndex }}, {{ $itemIndex }})"
                                            />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="flex justify-end border-t border-base-200 p-4">
                        <x-tallui-button
                            label="Add Product"
                            icon="o-plus"
                            class="btn-ghost btn-sm"
                            type="button"
                            wire:click="addItem({{ $boxIndex }})"
                        />
                    </div>
                </div>
            @empty
                <div class="py-6">
                    <x-tallui-empty-state title="No boxes yet" description="Add the shipment boxes first, then enter the products inside each box." icon="o-cube" size="sm">
                        <x-tallui-button label="Add Box" icon="o-plus" class="btn-primary btn-sm" type="button" wire:click="addBox" />
                    </x-tallui-empty-state>
                </div>
            @endforelse
        </div>
    </x-tallui-card>

    <div class="flex justify-end gap-2">
        <x-tallui-button label="Cancel" :link="route('inventory.dashboard')" class="btn-ghost" />
        <x-tallui-button label="Create Transfer" icon="o-check" class="btn-primary" type="submit" :spinner="'save'" />
    </div>

</form>
</div>

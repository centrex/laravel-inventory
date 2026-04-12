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

    {{-- Line Items --}}
    <x-tallui-card padding="none" :shadow="true">
        <x-slot:actions>
            <x-tallui-button label="Add Line" icon="o-plus" class="btn-ghost btn-sm" type="button" wire:click="addItem" />
        </x-slot:actions>

        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                        <th class="pl-5">Product</th>
                        <th class="w-32">Qty to Send</th>
                        <th class="pr-5 w-16"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-200">
                    @forelse ($items as $index => $item)
                        <tr wire:key="trf-item-{{ $index }}" class="hover:bg-base-50">
                            <td class="pl-5 py-2">
                                <x-tallui-select name="items.{{ $index }}.product_id" wire:model="items.{{ $index }}.product_id" class="select-sm w-full max-w-sm">
                                    <option value="">Select product…</option>
                                    @foreach ($products as $product)
                                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                                    @endforeach
                                </x-tallui-select>
                            </td>
                            <td class="py-2">
                                <x-tallui-input name="items.{{ $index }}.qty_sent" type="number" step="0.0001" min="0" wire:model="items.{{ $index }}.qty_sent" class="input-sm text-right w-28" />
                            </td>
                            <td class="pr-5 py-2 text-right">
                                <x-tallui-button icon="o-trash" class="btn-ghost btn-xs text-error" type="button" wire:click="removeItem({{ $index }})" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="py-6 text-center">
                                <x-tallui-empty-state title="No items yet" description="Add the products to transfer." icon="o-cube" size="sm">
                                    <x-tallui-button label="Add Line" icon="o-plus" class="btn-primary btn-sm" type="button" wire:click="addItem" />
                                </x-tallui-empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-tallui-card>

    <div class="flex justify-end gap-2">
        <x-tallui-button label="Cancel" :link="route('inventory.dashboard')" class="btn-ghost" />
        <x-tallui-button label="Create Transfer" icon="o-check" class="btn-primary" type="submit" :spinner="'save'" />
    </div>

</form>
</div>

<div>
<x-tallui-notification />

<x-tallui-page-header
    title="New Stock Adjustment"
    subtitle="Record count corrections, write-offs, and other stock variances."
    icon="o-scale"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'New Adjustment'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-badge type="warning">Adjustments</x-tallui-badge>
    </x-slot:actions>
</x-tallui-page-header>

<form wire:submit="save" class="space-y-4">

    {{-- Header --}}
    <x-tallui-card title="Adjustment Details" subtitle="Warehouse, reason, and reference date." icon="o-clipboard-document-check" :shadow="true">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <x-tallui-form-group label="Warehouse *" :error="$errors->first('warehouse_id')">
                <x-tallui-select name="warehouse_id" wire:model="warehouse_id" class="{{ $errors->has('warehouse_id') ? 'select-error' : '' }}">
                    <option value="">Select warehouse…</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>

            <x-tallui-form-group label="Reason *" :error="$errors->first('reason')">
                <x-tallui-select name="reason" wire:model="reason" class="{{ $errors->has('reason') ? 'select-error' : '' }}">
                    <option value="">Select reason…</option>
                    @foreach ($reasons as $reasonOption)
                        <option value="{{ $reasonOption->value }}">{{ $reasonOption->label() }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>

            <x-tallui-form-group label="Adjusted At">
                <x-tallui-input name="adjusted_at" type="date" wire:model="adjusted_at" />
            </x-tallui-form-group>

            <div class="md:col-span-2 lg:col-span-3">
                <x-tallui-form-group label="Notes">
                    <x-tallui-textarea name="notes" wire:model="notes" rows="2" placeholder="Description of why this adjustment is needed…" />
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
                        <th class="w-32">Actual Qty</th>
                        <th>Notes</th>
                        <th class="pr-5 w-16"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-200">
                    @forelse ($items as $index => $item)
                        <tr wire:key="adj-item-{{ $index }}" class="hover:bg-base-50">
                            <td class="pl-5 py-2">
                                <x-tallui-select name="items.{{ $index }}.product_id" wire:model="items.{{ $index }}.product_id" class="select-sm w-full max-w-sm">
                                    <option value="">Select product…</option>
                                    @foreach ($products as $product)
                                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                                    @endforeach
                                </x-tallui-select>
                            </td>
                            <td class="py-2">
                                <x-tallui-input name="items.{{ $index }}.qty_actual" type="number" step="0.0001" wire:model="items.{{ $index }}.qty_actual" class="input-sm text-right w-28" />
                            </td>
                            <td class="py-2">
                                <x-tallui-input name="items.{{ $index }}.notes" wire:model="items.{{ $index }}.notes" class="input-sm w-full" placeholder="Optional…" />
                            </td>
                            <td class="pr-5 py-2 text-right">
                                <x-tallui-button icon="o-trash" class="btn-ghost btn-xs text-error" type="button" wire:click="removeItem({{ $index }})" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-6 text-center">
                                <x-tallui-empty-state title="No items yet" description="Add products to adjust." icon="o-scale" size="sm">
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
        <x-tallui-button label="Create Adjustment" icon="o-check" class="btn-warning" type="submit" :spinner="'save'" />
    </div>

</form>
</div>

<div>
<x-tallui-notification />

<x-tallui-page-header
    title="New Sale Order"
    subtitle="Capture outbound sales with tier-based or manual pricing."
    icon="o-shopping-cart"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'New Sale Order'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-badge type="success">Sales</x-tallui-badge>
    </x-slot:actions>
</x-tallui-page-header>

<form wire:submit="save" class="space-y-4">

    {{-- Header --}}
    <x-tallui-card title="Order Details" subtitle="Customer, pricing tier, and currency settings." icon="o-banknotes" :shadow="true">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <x-tallui-form-group label="Warehouse *" :error="$errors->first('warehouse_id')">
                <x-tallui-select name="warehouse_id" wire:model="warehouse_id" class="{{ $errors->has('warehouse_id') ? 'select-error' : '' }}">
                    <option value="">Select warehouse…</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>

            <x-tallui-form-group label="Customer">
                <x-tallui-select name="customer_id" wire:model="customer_id">
                    <option value="">Walk-in / none</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>

            <x-tallui-form-group label="Default Price Tier">
                <x-tallui-select name="price_tier_code" wire:model="price_tier_code">
                    @foreach ($priceTiers as $tier)
                        <option value="{{ $tier->code }}">{{ $tier->name }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>

            <x-tallui-form-group label="Currency">
                <x-tallui-input name="currency" wire:model="currency" placeholder="BDT" />
            </x-tallui-form-group>

            <x-tallui-form-group label="Exchange Rate (BDT)">
                <x-tallui-input name="exchange_rate" type="number" step="0.0001" wire:model="exchange_rate" />
            </x-tallui-form-group>

            <x-tallui-form-group label="Tax (Local)">
                <x-tallui-input name="tax_local" type="number" step="0.0001" wire:model="tax_local" placeholder="0.00" />
            </x-tallui-form-group>

            <x-tallui-form-group label="Discount (Local)">
                <x-tallui-input name="discount_local" type="number" step="0.0001" wire:model="discount_local" placeholder="0.00" />
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
            <x-tallui-button label="Add Line" icon="o-plus" class="btn-ghost btn-sm" type="button" wire:click="addItem" />
        </x-slot:actions>

        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                        <th class="pl-5 w-52">Product</th>
                        <th class="w-24">Qty</th>
                        <th class="w-32">Tier Override</th>
                        <th class="w-32">Unit Price (Local)</th>
                        <th class="w-24">Discount %</th>
                        <th>Notes</th>
                        <th class="pr-5 w-16"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-200">
                    @forelse ($items as $index => $item)
                        <tr wire:key="so-item-{{ $index }}" class="hover:bg-base-50">
                            <td class="pl-5 py-2">
                                <x-tallui-select name="items.{{ $index }}.product_id" wire:model="items.{{ $index }}.product_id" class="select-sm w-full">
                                    <option value="">Select product…</option>
                                    @foreach ($products as $product)
                                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                                    @endforeach
                                </x-tallui-select>
                            </td>
                            <td class="py-2">
                                <x-tallui-input name="items.{{ $index }}.qty_ordered" type="number" step="0.0001" min="0" wire:model="items.{{ $index }}.qty_ordered" class="input-sm text-right w-full" />
                            </td>
                            <td class="py-2">
                                <x-tallui-select name="items.{{ $index }}.price_tier_code" wire:model="items.{{ $index }}.price_tier_code" class="select-sm w-full">
                                    <option value="">Default</option>
                                    @foreach ($priceTiers as $tier)
                                        <option value="{{ $tier->code }}">{{ $tier->name }}</option>
                                    @endforeach
                                </x-tallui-select>
                            </td>
                            <td class="py-2">
                                <x-tallui-input name="items.{{ $index }}.unit_price_local" type="number" step="0.0001" min="0" wire:model="items.{{ $index }}.unit_price_local" class="input-sm text-right w-full" />
                            </td>
                            <td class="py-2">
                                <x-tallui-input name="items.{{ $index }}.discount_pct" type="number" step="0.01" min="0" max="100" wire:model="items.{{ $index }}.discount_pct" class="input-sm text-right w-full" placeholder="0" />
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
                            <td colspan="7" class="py-6 text-center">
                                <x-tallui-empty-state title="No items yet" description="Add at least one product line." icon="o-shopping-bag" size="sm">
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
        <x-tallui-button label="Create Sale Order" icon="o-check" class="btn-primary" type="submit" :spinner="'save'" />
    </div>

</form>
</div>

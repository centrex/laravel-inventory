<div>
<x-tallui-notification />

<x-tallui-page-header
    :title="'Edit Prices — ' . $product->name . ' — ' . $warehouse->name"
    subtitle="Update every price tier for this product at this warehouse in one save."
    icon="o-tag"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Product Prices', 'href' => route('inventory.entities.product-prices.index')],
            ['label' => $product->name],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-button
            label="Back to Product Prices"
            icon="o-arrow-left"
            :link="route('inventory.entities.product-prices.index')"
            class="btn-ghost btn-sm"
        />
    </x-slot:actions>
</x-tallui-page-header>

<x-tallui-card
    title="Price Tiers"
    :subtitle="'SKU ' . ($product->sku ?? '—') . ' — leave a tier blank to leave it unset.'"
    icon="o-currency-dollar"
    :shadow="true"
>
    <form wire:submit="save" class="space-y-4">
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                        <th class="pl-4">Tier</th>
                        <th>Price</th>
                        <th>Cost Price</th>
                        <th>MOQ</th>
                        <th>Currency</th>
                        <th class="text-center">Active</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-200">
                    @foreach ($tierOptions as $tier)
                        <tr class="even:bg-base-200/50 hover:bg-base-200">
                            <td class="pl-4 py-3 text-sm font-medium">{{ $tier->label() }}</td>
                            <td class="py-2">
                                <x-tallui-input
                                    type="number"
                                    step="0.0001"
                                    placeholder="0.00"
                                    wire:model="tiers.{{ $tier->value }}.price_amount"
                                    :class="$errors->has('tiers.' . $tier->value . '.price_amount') ? 'input-error' : ''"
                                    class="input-sm w-32"
                                />
                            </td>
                            <td class="py-2">
                                <x-tallui-input
                                    type="number"
                                    step="0.0001"
                                    placeholder="0.00"
                                    wire:model="tiers.{{ $tier->value }}.cost_price"
                                    class="input-sm w-28"
                                />
                            </td>
                            <td class="py-2">
                                <x-tallui-input
                                    type="number"
                                    step="1"
                                    wire:model="tiers.{{ $tier->value }}.moq"
                                    class="input-sm w-20"
                                />
                            </td>
                            <td class="py-2">
                                <x-tallui-input
                                    type="text"
                                    placeholder="BDT"
                                    wire:model="tiers.{{ $tier->value }}.currency"
                                    class="input-sm w-20 uppercase"
                                />
                            </td>
                            <td class="py-2 text-center">
                                <x-tallui-checkbox
                                    wire:model="tiers.{{ $tier->value }}.is_active"
                                />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-end gap-2 pt-2 border-t border-base-200">
            <x-tallui-button
                label="Back to Product Prices"
                icon="o-arrow-left"
                :link="route('inventory.entities.product-prices.index')"
                class="btn-ghost"
            />
            <x-tallui-button
                label="Save Prices"
                icon="o-check"
                class="btn-primary"
                type="submit"
                :spinner="'save'"
            />
        </div>
    </form>
</x-tallui-card>
</div>

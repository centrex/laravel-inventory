<div>
<x-tallui-notification />

<x-tallui-page-header
    title="Product Prices"
    subtitle="Browse and edit every price tier for a product at a warehouse, all at once."
    icon="o-tag"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Product Prices'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <div class="w-64">
            <x-tallui-input
                placeholder="Search products…"
                wire:model.live.debounce.300ms="search"
                class="input-sm"
            />
        </div>
        <div class="w-48">
            <x-tallui-select wire:model.live="filterWarehouseId" class="select-sm">
                <option value="">All warehouses</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                @endforeach
            </x-tallui-select>
        </div>
    </x-slot:actions>
</x-tallui-page-header>

<x-tallui-card padding="none" :shadow="true">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                    <th class="pl-5">Product</th>
                    <th>Warehouse</th>
                    @foreach ($tiers as $tier)
                        <th class="text-right">{{ $tier->label() }}</th>
                    @endforeach
                    <th class="pr-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse ($rows as $row)
                    <tr class="hover:bg-base-50">
                        <td class="pl-5 py-2 text-sm">
                            <div class="font-medium">{{ $row['product']->name }}</div>
                            <div class="text-xs text-base-content/50">{{ $row['product']->sku }}</div>
                        </td>
                        <td class="text-sm">{{ $row['warehouse']->name }}</td>
                        @foreach ($tiers as $tier)
                            @php $price = $row['prices'][$tier->value] ?? null; @endphp
                            <td class="text-right text-sm">
                                @if ($price)
                                    {{ number_format((float) $price->price_amount, 2) }}
                                @else
                                    <span class="text-base-content/30">—</span>
                                @endif
                            </td>
                        @endforeach
                        <td class="pr-5 text-right">
                            <x-tallui-button
                                icon="o-pencil-square"
                                label="Edit Prices"
                                :link="route('inventory.entities.product-prices.edit', ['recordId' => $row['product']->getKey(), 'warehouseId' => $row['warehouse']->getKey()])"
                                class="btn-ghost btn-xs"
                                :responsive="true"
                                wire:navigate
                            />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($tiers) + 3 }}" class="py-8">
                            <x-tallui-empty-state
                                title="No products found"
                                description="Adjust your search or warehouse filter."
                                icon="o-tag"
                                size="sm"
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($stockRows->hasPages())
        <div class="px-5 py-3 border-t border-base-200">
            {{ $stockRows->links() }}
        </div>
    @endif
</x-tallui-card>
</div>

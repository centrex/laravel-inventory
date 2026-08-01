<div>
<x-tallui-card title="Low Stock" subtitle="Available quantity (on hand − reserved) at or below reorder point." icon="o-exclamation-triangle" :shadow="true" padding="none">
    <x-slot:actions>
        <div class="flex items-center gap-2">
            <div class="w-56">
                <x-tallui-select wire:model.live="warehouseId" wire:loading.attr="disabled" wire:target="warehouseId" class="select-sm">
                    <option value="">All warehouses</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                    @endforeach
                </x-tallui-select>
            </div>
            <span wire:loading wire:target="warehouseId" class="flex items-center gap-1 text-xs text-base-content/60">
                <span class="loading loading-spinner loading-xs"></span>
                Updating…
            </span>
        </div>
    </x-slot:actions>

    <div class="stats shadow w-full mb-4">
        <x-tallui-stat title="Low Stock Items" :value="number_format($lowStock->count())" icon="o-exclamation-triangle" :icon-color="$lowStock->count() > 0 ? 'text-warning' : 'text-success'" />
    </div>

    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                    <th class="pl-5">Product</th>
                    <th>Warehouse</th>
                    <th class="text-right">Available</th>
                    <th class="pr-5 text-right">Reorder Point</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse ($lowStock as $row)
                    <tr class="even:bg-base-200/50 hover:bg-base-200">
                        <td class="pl-5">
                            <div class="font-medium text-sm">{{ $row['product_name'] }}</div>
                            <div class="text-xs text-base-content/50">{{ $row['product_sku'] }}</div>
                        </td>
                        <td class="text-sm">{{ $row['warehouse_name'] }}</td>
                        <td class="text-right font-mono text-sm font-semibold text-warning">{{ number_format($row['qty_available'], 2) }}</td>
                        <td class="pr-5 text-right font-mono text-sm">{{ number_format($row['reorder_point'], 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">
                            <x-tallui-empty-state title="No low stock items" description="Every product is above its reorder point." icon="o-check-circle" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-tallui-card>
</div>

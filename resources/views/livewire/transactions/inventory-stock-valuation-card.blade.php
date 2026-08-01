<div>
<x-tallui-card title="Stock Valuation" subtitle="Weighted-average cost valuation per product/warehouse." icon="o-banknotes" :shadow="true" padding="none">
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
        <x-tallui-stat title="Stock Value" :value="number_format($totalStockValue, 2)" icon="o-banknotes" />
        <x-tallui-stat title="Products On Hand" :value="number_format($productCount)" icon="o-cube" />
    </div>

    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                    <th class="pl-5">Product</th>
                    <th>Warehouse</th>
                    <th class="text-right">On Hand</th>
                    <th class="text-right">Available</th>
                    <th class="text-right">WAC</th>
                    <th class="pr-5 text-right">Total Value</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse ($valuation as $row)
                    <tr class="even:bg-base-200/50 hover:bg-base-200">
                        <td class="pl-5">
                            <div class="font-medium text-sm">{{ $row['product'] }}</div>
                            <div class="text-xs text-base-content/50">{{ $row['sku'] }}</div>
                        </td>
                        <td class="text-sm">{{ $row['warehouse'] }}</td>
                        <td class="text-right font-mono text-sm">{{ number_format($row['qty_on_hand'], 2) }}</td>
                        <td class="text-right font-mono text-sm">{{ number_format($row['qty_available'], 2) }}</td>
                        <td class="text-right font-mono text-sm">{{ number_format($row['wac_amount'], 4) }}</td>
                        <td class="pr-5 text-right font-mono text-sm font-semibold">{{ number_format($row['total_value_amount'], 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-tallui-empty-state title="No stock on hand" description="No products with stock in this warehouse yet." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-tallui-card>
</div>

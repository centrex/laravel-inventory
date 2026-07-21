<div>
<x-tallui-page-header title="Stock Report" subtitle="Inventory valuation and low-stock positions across warehouses." icon="o-archive-box">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Reports', 'href' => route('inventory.reports.index')],
            ['label' => 'Stock'],
        ]" />
    </x-slot:breadcrumbs>
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
</x-tallui-page-header>

<div class="stats shadow w-full mb-6">
    <x-tallui-stat title="Stock Value" :value="number_format($totalStockValue, 2)" icon="o-banknotes" />
    <x-tallui-stat title="Products On Hand" :value="number_format($productCount)" icon="o-cube" />
    <x-tallui-stat title="Low Stock Items" :value="number_format($lowStock->count())" icon="o-exclamation-triangle" :icon-color="$lowStock->count() > 0 ? 'text-warning' : 'text-success'" />
</div>

<x-tallui-card title="Low Stock" subtitle="Available quantity (on hand − reserved) at or below reorder point." icon="o-exclamation-triangle" :shadow="true" padding="none">
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
                @forelse ($lowStock as $wp)
                    <tr class="even:bg-base-200/50 hover:bg-base-200">
                        <td class="pl-5">
                            <div class="font-medium text-sm">{{ $wp->product?->name }}</div>
                            <div class="text-xs text-base-content/50">{{ $wp->product?->sku }}</div>
                        </td>
                        <td class="text-sm">{{ $wp->warehouse?->name }}</td>
                        <td class="text-right font-mono text-sm font-semibold text-warning">{{ number_format($wp->qtyAvailable(), 2) }}</td>
                        <td class="pr-5 text-right font-mono text-sm">{{ number_format((float) $wp->reorder_point, 2) }}</td>
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

<div class="mt-6">
    <x-tallui-card title="Stock Valuation" subtitle="Weighted-average cost valuation per product/warehouse." icon="o-banknotes" :shadow="true" padding="none">
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
</div>

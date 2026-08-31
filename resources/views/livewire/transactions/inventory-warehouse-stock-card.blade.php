<div>
<x-tallui-card
    title="Warehouse Stock"
    subtitle="Weighted-average stock value and net saleable stock (on-hand − reserved) by warehouse."
    icon="o-building-office-2"
    :shadow="true"
    class="mb-6"
>
    <x-slot:actions>
        <x-tallui-button icon="o-arrow-path" class="btn-ghost btn-sm btn-circle" wire:click="refresh" :spinner="'refresh'" tooltip="Refresh" />
    </x-slot:actions>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 mb-5">
        <div class="rounded-2xl border border-base-200 bg-base-100 p-4">
            <div class="text-xs font-semibold text-base-content/50 uppercase mb-2">Total Stock Value</div>
            <div class="text-2xl font-bold text-primary">{{ number_format((float) $totalStockValue, 2) }}</div>
            <div class="mt-1 text-xs text-base-content/50">weighted-average, across all warehouses</div>
        </div>
        <div class="rounded-2xl border border-base-200 bg-base-100 p-4">
            <div class="text-xs font-semibold text-base-content/50 uppercase mb-2">Net Saleable Stock</div>
            <div class="text-2xl font-bold text-success">{{ number_format((float) $totalNetSaleableStock, 2) }}</div>
            <div class="mt-1 text-xs text-base-content/50">on-hand minus reserved, across all warehouses</div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
        @forelse ($warehouseStockValues as $warehouseStock)
            <div class="rounded-2xl border border-base-200 bg-base-100 p-4">
                <div class="text-sm font-semibold text-base-content">{{ $warehouseStock['name'] }}</div>
                <div class="mt-2 text-2xl font-bold text-primary">
                    {{ number_format((float) $warehouseStock['stock_value'], 2) }}
                </div>
                <div class="mt-1 text-xs text-base-content/50">{{ $warehouseStock['currency'] }} weighted stock value</div>
                <div class="mt-3 pt-3 border-t border-base-200">
                    <div class="text-lg font-bold text-success">
                        {{ number_format((float) $warehouseStock['net_saleable_stock'], 2) }}
                    </div>
                    <div class="mt-0.5 text-xs text-base-content/50">net saleable stock (qty)</div>
                </div>
            </div>
        @empty
            <div class="col-span-full">
                <x-tallui-empty-state
                    title="No warehouses yet"
                    description="Create warehouses and stock records to view valuation by location."
                    icon="o-building-office"
                    size="sm"
                />
            </div>
        @endforelse
    </div>
</x-tallui-card>
</div>

<div>
@php
    $bucketColor = fn (string $bucket) => match ($bucket) {
        '0-30'  => 'text-success',
        '31-60' => 'text-info',
        '61-90' => 'text-warning',
        '90+'   => 'text-error',
        default => 'text-base-content/50',
    };

    // Mirrors Inventory::agingBucket() — only used here to color the single "oldest" column.
    $bucketFor = fn (?int $days) => match (true) {
        $days === null => 'unknown',
        $days <= 30    => '0-30',
        $days <= 60    => '31-60',
        $days <= 90    => '61-90',
        default        => '90+',
    };
@endphp

<x-tallui-card title="Stock Aging" subtitle="On-hand stock value, traced through purchases + sales (FIFO) back to the receipt it came from." icon="o-archive-box" :shadow="true">
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
            <x-tallui-button label="Export Excel" icon="o-arrow-down-tray" wire:click="exportExcel" class="btn-outline btn-sm" />
        </div>
    </x-slot:actions>

    <div class="stats shadow w-full mb-4">
        @foreach ($stockAgingSummary as $bucket => $value)
            <x-tallui-stat :title="$bucket === 'unknown' ? 'Untraced' : $bucket . ' days'" :value="number_format($value, 2)" :icon-color="$bucketColor($bucket)" />
        @endforeach
    </div>

    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                    <th class="pl-5">Product</th>
                    <th>Warehouse</th>
                    <th class="text-right">On Hand</th>
                    <th class="text-right">Total Value</th>
                    @foreach ($stockAgingSummary as $bucket => $value)
                        <th class="text-right">{{ $bucket === 'unknown' ? 'Untraced' : $bucket . 'd' }}</th>
                    @endforeach
                    <th class="pr-5 text-right">Oldest</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse ($stockAging as $row)
                    <tr class="even:bg-base-200/50 hover:bg-base-200">
                        <td class="pl-5">
                            <div class="font-medium text-sm">{{ $row['product'] }}</div>
                            <div class="text-xs text-base-content/50">{{ $row['sku'] }}</div>
                        </td>
                        <td class="text-sm">{{ $row['warehouse'] }}</td>
                        <td class="text-right font-mono text-sm">{{ number_format($row['qty_on_hand'], 2) }}</td>
                        <td class="text-right font-mono text-sm font-semibold">{{ number_format($row['total_value_amount'], 2) }}</td>
                        @foreach ($row['buckets'] as $bucket => $amounts)
                            <td class="text-right font-mono text-xs {{ $amounts['qty'] > 0 ? $bucketColor($bucket) : 'text-base-content/30' }}">
                                {{ $amounts['qty'] > 0 ? number_format($amounts['qty'], 2) : '—' }}
                            </td>
                        @endforeach
                        <td class="pr-5 text-right">
                            <span class="font-mono text-sm {{ $bucketColor($bucketFor($row['oldest_days_in_stock'])) }}">
                                {{ $row['oldest_days_in_stock'] !== null ? $row['oldest_days_in_stock'] . 'd' : '—' }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ 5 + count($stockAgingSummary) }}">
                            <x-tallui-empty-state title="No stock on hand" description="No products with stock in this warehouse yet." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-tallui-card>
</div>

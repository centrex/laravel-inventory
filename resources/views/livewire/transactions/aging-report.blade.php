<div>
<x-tallui-page-header title="Aging Report" subtitle="Stock aging and customer due aging in 30-day buckets." icon="o-clock">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Reports', 'href' => route('inventory.reports.index')],
            ['label' => 'Aging'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <div class="w-56">
            <x-tallui-select wire:model.live="warehouseId" class="select-sm">
                <option value="">All warehouses</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                @endforeach
            </x-tallui-select>
        </div>
    </x-slot:actions>
</x-tallui-page-header>

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

<div class="mt-6">
    <x-tallui-card title="Due Aging" subtitle="Outstanding customer receivables bucketed by days since order date." icon="o-banknotes">
        <div class="stats shadow w-full mb-4">
            @foreach ($dueAgingSummary as $bucket => $value)
                <x-tallui-stat :title="$bucket === 'unknown' ? 'No Date' : $bucket . ' days'" :value="number_format($value, 2)" :icon-color="$bucketColor($bucket)" />
            @endforeach
        </div>

        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                        <th class="pl-5">Customer</th>
                        <th>SO #</th>
                        <th>Ordered</th>
                        <th class="text-right">Due Amount</th>
                        <th class="pr-5">Age</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-200">
                    @forelse ($dueAging as $row)
                        <tr class="even:bg-base-200/50 hover:bg-base-200">
                            <td class="pl-5 text-sm">{{ $row['customer'] ?? '—' }}</td>
                            <td class="text-sm">{{ $row['so_number'] }}</td>
                            <td class="text-sm">{{ $row['ordered_at']?->format('M d, Y') ?? '—' }}</td>
                            <td class="text-right font-mono text-sm font-semibold">{{ number_format($row['due_amount'], 2) }}</td>
                            <td class="pr-5">
                                <span class="font-mono text-sm {{ $bucketColor($row['age_bucket']) }}">
                                    {{ $row['days_overdue'] !== null ? $row['days_overdue'] . 'd' : '—' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-tallui-empty-state title="No outstanding dues" description="No customers currently owe an outstanding balance." icon="o-check-circle" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-tallui-card>
</div>
</div>

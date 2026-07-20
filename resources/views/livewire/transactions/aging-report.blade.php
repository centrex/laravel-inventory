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

<x-tallui-tab :tabs="[
    ['id' => 'stock_aging', 'label' => 'Stock Aging', 'icon' => 'o-archive-box'],
    ['id' => 'due_aging', 'label' => 'Due Aging', 'icon' => 'o-banknotes'],
]" active="stock_aging" variant="bordered">
<x-slot:stock_aging>

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

</x-slot:stock_aging>

<x-slot:due_aging>

    <x-tallui-card
        title="Due Aging by Customer"
        :subtitle="'Outstanding customer receivables from ' . \Illuminate\Support\Carbon::parse($fromDate)->format('M d, Y') . ' onward, bucketed by days since order date.'"
        icon="o-banknotes"
        padding="none"
    >
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <span class="text-xs text-base-content/50 whitespace-nowrap">From</span>
                <x-tallui-input type="date" wire:model.live="fromDate" class="input-sm" />
            </div>
        </x-slot:actions>

        <div class="stats shadow w-full mb-4 mx-5 mt-5">
            @foreach ($dueAgingSummary as $bucket => $value)
                <x-tallui-stat :title="$bucket === 'unknown' ? 'No Date' : $bucket . ' days'" :value="number_format($value, 2)" :icon-color="$bucketColor($bucket)" />
            @endforeach
        </div>

        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                        <th class="pl-5">Customer</th>
                        <th class="text-right">Orders</th>
                        <th class="text-right">Oldest (days)</th>
                        <th class="text-right">0-30</th>
                        <th class="text-right">31-60</th>
                        <th class="text-right">61-90</th>
                        <th class="text-right">90+</th>
                        <th class="text-right">Total Due</th>
                        <th class="pr-5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-200">
                    @forelse ($customerDueAging as $row)
                        <tr class="even:bg-base-200/50 hover:bg-base-200">
                            <td class="pl-5">
                                <div class="font-medium text-sm">{{ $row['customer'] }}</div>
                            </td>
                            <td class="text-right text-sm text-base-content/60">{{ $row['orders_count'] }}</td>
                            <td class="text-right font-mono text-sm {{ $bucketColor($bucketFor($row['oldest_days_overdue'])) }}">
                                {{ $row['oldest_days_overdue'] ?? '—' }}
                            </td>
                            <td class="text-right font-mono text-sm">{{ $row['buckets']['0-30'] > 0 ? number_format($row['buckets']['0-30'], 2) : '—' }}</td>
                            <td class="text-right font-mono text-sm">{{ $row['buckets']['31-60'] > 0 ? number_format($row['buckets']['31-60'], 2) : '—' }}</td>
                            <td class="text-right font-mono text-sm text-warning">{{ $row['buckets']['61-90'] > 0 ? number_format($row['buckets']['61-90'], 2) : '—' }}</td>
                            <td class="text-right font-mono text-sm text-error">{{ $row['buckets']['90+'] > 0 ? number_format($row['buckets']['90+'], 2) : '—' }}</td>
                            <td class="text-right font-mono text-sm font-semibold">{{ number_format($row['total_due'], 2) }}</td>
                            <td class="pr-5 text-right">
                                <x-tallui-button icon="o-eye" class="btn-ghost btn-xs" wire:click="viewCustomerAging({{ $row['customer_id'] }})" :tooltip="'View invoices'" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9">
                                <x-tallui-empty-state title="No outstanding dues" description="No customers currently owe an outstanding balance." icon="o-check-circle" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-tallui-card>

</x-slot:due_aging>
</x-tallui-tab>

<x-tallui-modal id="customer-aging-detail" :title="'Due Invoices — ' . ($agingCustomerName ?? '')" icon="o-banknotes" size="lg">
    <div class="overflow-x-auto rounded-xl border border-base-200">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-200 text-xs text-base-content/60 uppercase tracking-wide">
                    <th class="pl-4">Order / Invoice</th>
                    <th>Ordered</th>
                    <th class="text-right">Days Overdue</th>
                    <th class="pr-4 text-right">Due Amount</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse ($agingCustomerOrders as $row)
                    <tr>
                        <td class="pl-4 font-medium text-sm">{{ $row['so_number'] }}</td>
                        <td class="text-sm text-base-content/70">{{ $row['ordered_at']?->format('M d, Y') ?? '—' }}</td>
                        <td class="text-right text-sm {{ $bucketColor($row['age_bucket']) }}">{{ $row['days_overdue'] ?? '—' }}</td>
                        <td class="pr-4 text-right font-mono text-sm font-semibold">{{ number_format($row['due_amount'], 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="py-4 text-center text-sm text-base-content/60">No outstanding orders.</td>
                    </tr>
                @endforelse
            </tbody>
            @if ($agingCustomerOrders->isNotEmpty())
                <tfoot>
                    <tr class="border-t border-base-200">
                        <td colspan="3" class="pl-4 py-2 text-right text-sm font-medium text-base-content/60">Total Due</td>
                        <td class="pr-4 py-2 text-right font-mono text-sm font-bold">{{ number_format($agingCustomerOrders->sum('due_amount'), 2) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    <x-slot:footer>
        <x-tallui-button wire:click="closeCustomerAgingModal" class="btn-ghost">Close</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>
</div>

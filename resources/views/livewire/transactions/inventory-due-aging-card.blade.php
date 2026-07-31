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

<x-tallui-card
    title="Due Aging by Customer"
    :subtitle="'Outstanding customer receivables from ' . \Illuminate\Support\Carbon::parse($fromDate)->format('M d, Y') . ' onward, bucketed by days since order date.'"
    icon="o-banknotes"
    padding="none"
>
    <x-slot:actions>
        <div class="flex items-center gap-2">
            <span class="text-xs text-base-content/50 whitespace-nowrap">From</span>
            <x-tallui-input type="date" wire:model.live="fromDate" wire:loading.attr="disabled" wire:target="fromDate" class="input-sm" />
            <span wire:loading wire:target="fromDate" class="flex items-center gap-1 text-xs text-base-content/60">
                <span class="loading loading-spinner loading-xs"></span>
                Updating…
            </span>
            <x-tallui-button label="Export Excel" icon="o-arrow-down-tray" wire:click="exportExcel" class="btn-outline btn-sm" />
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

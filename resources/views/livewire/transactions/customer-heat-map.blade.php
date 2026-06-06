<div>
<x-tallui-notification />

<x-tallui-page-header
    title="Customer Sales Heat Map"
    subtitle="Sales intensity by zone and area for the selected period."
    icon="o-map"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Reports', 'href' => route('inventory.reports.index')],
            ['label' => 'Heat Map'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-button
            label="Reports"
            icon="o-arrow-left"
            :link="route('inventory.reports.index')"
            class="btn-ghost btn-sm"
        />
    </x-slot:actions>
</x-tallui-page-header>

{{-- Filters --}}
<x-tallui-card icon="o-funnel" :shadow="true" class="mb-4">
    <div class="flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-36">
            <label class="mb-1 block text-xs font-medium text-base-content/60">From</label>
            <input
                type="date"
                wire:model.live="startDate"
                class="input input-bordered input-sm w-full"
            />
        </div>
        <div class="flex-1 min-w-36">
            <label class="mb-1 block text-xs font-medium text-base-content/60">To</label>
            <input
                type="date"
                wire:model.live="endDate"
                class="input input-bordered input-sm w-full"
            />
        </div>
        <div class="flex-1 min-w-36">
            <label class="mb-1 block text-xs font-medium text-base-content/60">Metric</label>
            <select wire:model.live="metric" class="select select-bordered select-sm w-full">
                <option value="revenue">Revenue (BDT)</option>
                <option value="orders">Order Count</option>
                <option value="customers">Unique Customers</option>
                <option value="avg_order">Avg Order Value</option>
            </select>
        </div>
        <div class="flex gap-2">
            <button type="button" wire:click="$set('startDate', '{{ now()->startOfMonth()->toDateString() }}')" class="btn btn-ghost btn-sm">This Month</button>
            <button type="button" wire:click="$set('startDate', '{{ now()->subDays(29)->toDateString() }}')" class="btn btn-ghost btn-sm">30d</button>
            <button type="button" wire:click="$set('startDate', '{{ now()->subDays(89)->toDateString() }}')" class="btn btn-ghost btn-sm">90d</button>
            <button type="button" wire:click="$set('startDate', '{{ now()->subYear()->startOfYear()->toDateString() }}')" class="btn btn-ghost btn-sm">YTD</button>
        </div>
    </div>
</x-tallui-card>

@php
    $zones       = $heatmap['zones'];
    $areas       = $heatmap['areas'];
    $cells       = $heatmap['cells'];
    $zoneTotals  = $heatmap['zone_totals'];
    $areaTotals  = $heatmap['area_totals'];
    $maxValue    = (float) ($heatmap['max_value'] ?? 1);
    $grandTotal  = $heatmap['grand_total'];
    $metric      = $heatmap['metric'];
    $metricLabel = match ($metric) {
        'orders'    => 'Orders',
        'customers' => 'Customers',
        'avg_order' => 'Avg Order',
        default     => 'Revenue',
    };

    $cellValue = fn (array $cell): float => match ($metric) {
        'orders'    => (float) $cell['orders'],
        'customers' => (float) $cell['customers'],
        'avg_order' => (float) $cell['avg_order'],
        default     => (float) $cell['revenue'],
    };
    $totalValue = fn (array $t): float => match ($metric) {
        'orders'    => (float) $t['orders'],
        'customers' => (float) $t['customers'],
        'avg_order' => $t['orders'] > 0 ? round($t['revenue'] / $t['orders'], 2) : 0.0,
        default     => (float) $t['revenue'],
    };
    $formatValue = fn (float $v): string => match ($metric) {
        'orders'    => number_format($v, 0),
        'customers' => number_format($v, 0),
        'avg_order' => number_format($v, 0),
        default     => number_format($v, 0),
    };
    $grandValue = match ($metric) {
        'orders'    => (float) $grandTotal['orders'],
        'customers' => (float) $grandTotal['customers'],
        'avg_order' => $grandTotal['orders'] > 0 ? round($grandTotal['revenue'] / $grandTotal['orders'], 2) : 0.0,
        default     => (float) $grandTotal['revenue'],
    };

    // Color channels for intensity scale: primary blue rgb(99 102 241 = indigo-500)
    $rgb = match ($metric) {
        'orders'    => [34, 197, 94],   // green-500
        'customers' => [168, 85, 247],  // purple-500
        'avg_order' => [245, 158, 11],  // amber-500
        default     => [59, 130, 246],  // blue-500
    };
    [$r, $g, $b] = $rgb;
@endphp

@if (empty($zones))
    <x-tallui-empty-state
        title="No sales data"
        description="No fulfilled or active sale orders found for the selected period."
        icon="o-map"
        size="md"
    />
@else

{{-- Summary strip --}}
<div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
    <div class="rounded-xl border border-base-200 bg-base-50 p-4">
        <div class="text-xs font-medium uppercase tracking-wide text-base-content/50">Total Revenue</div>
        <div class="mt-1.5 text-xl font-bold tabular-nums">{{ number_format($grandTotal['revenue'], 0) }}</div>
        <div class="text-xs text-base-content/40">BDT</div>
    </div>
    <div class="rounded-xl border border-base-200 bg-base-50 p-4">
        <div class="text-xs font-medium uppercase tracking-wide text-base-content/50">Total Orders</div>
        <div class="mt-1.5 text-xl font-bold tabular-nums">{{ number_format($grandTotal['orders'], 0) }}</div>
    </div>
    <div class="rounded-xl border border-base-200 bg-base-50 p-4">
        <div class="text-xs font-medium uppercase tracking-wide text-base-content/50">Unique Customers</div>
        <div class="mt-1.5 text-xl font-bold tabular-nums">{{ number_format($grandTotal['customers'], 0) }}</div>
    </div>
    <div class="rounded-xl border border-base-200 bg-base-50 p-4">
        <div class="text-xs font-medium uppercase tracking-wide text-base-content/50">Zones × Areas</div>
        <div class="mt-1.5 text-xl font-bold tabular-nums">{{ count($zones) }} × {{ count($areas) }}</div>
    </div>
</div>

{{-- Heat map --}}
<x-tallui-card
    title="Zone × Area Heat Map"
    :subtitle="'Showing ' . $metricLabel . ' · ' . ($heatmap['period']['start'] ?: 'all time') . ' → ' . ($heatmap['period']['end'] ?: 'today')"
    icon="o-table-cells"
    :shadow="true"
>
    {{-- Legend --}}
    <div class="mb-4 flex items-center gap-2 text-xs text-base-content/50">
        <span>Low</span>
        <div class="flex h-3 flex-1 max-w-40 overflow-hidden rounded">
            @for ($step = 0; $step <= 9; $step++)
                @php $alpha = 0.05 + ($step / 9) * 0.85; @endphp
                <div class="flex-1" style="background: rgba({{ $r }},{{ $g }},{{ $b }},{{ round($alpha, 2) }})"></div>
            @endfor
        </div>
        <span>High</span>
        <span class="ml-4 text-base-content/40">(metric: {{ $metricLabel }})</span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full border-separate border-spacing-0.5 text-xs">
            {{-- Header row: area names --}}
            <thead>
                <tr>
                    {{-- Corner --}}
                    <th class="sticky left-0 z-10 min-w-28 rounded-lg bg-base-200 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-base-content/60">
                        Zone \ Area
                    </th>
                    @foreach ($areas as $area)
                        <th class="min-w-24 rounded-lg bg-base-200 px-2 py-2 text-center font-semibold text-base-content/70">
                            {{ $area }}
                        </th>
                    @endforeach
                    {{-- Zone total header --}}
                    <th class="min-w-24 rounded-lg bg-base-300 px-2 py-2 text-center font-semibold text-base-content/70">
                        Total
                    </th>
                </tr>
            </thead>

            <tbody>
                @foreach ($zones as $zone)
                    <tr>
                        {{-- Zone label --}}
                        <td class="sticky left-0 z-10 rounded-lg bg-base-100 px-3 py-2 font-semibold text-base-content/80 shadow-sm">
                            {{ $zone }}
                        </td>

                        @foreach ($areas as $area)
                            @php
                                $cell  = $cells[$zone][$area];
                                $val   = $cellValue($cell);
                                $alpha = $maxValue > 0 ? min(0.9, max(0.0, $val / $maxValue * 0.9) + ($val > 0 ? 0.07 : 0.0)) : 0.0;
                                $textLight = $alpha > 0.5;
                            @endphp
                            <td
                                class="rounded-lg text-center transition-transform hover:scale-105 hover:shadow-md cursor-default"
                                style="background: rgba({{ $r }},{{ $g }},{{ $b }},{{ round($alpha, 3) }}); min-width: 80px;"
                                title="{{ $zone }} / {{ $area }} — Revenue: {{ number_format($cell['revenue'], 0) }} BDT · Orders: {{ $cell['orders'] }} · Customers: {{ $cell['customers'] }} · Avg: {{ number_format($cell['avg_order'], 0) }} BDT"
                            >
                                @if ($val > 0)
                                    <div class="px-2 py-2">
                                        <div class="font-semibold tabular-nums {{ $textLight ? 'text-white' : 'text-base-content' }}">
                                            {{ $formatValue($val) }}
                                        </div>
                                        @if ($metric === 'revenue')
                                            <div class="mt-0.5 text-[10px] {{ $textLight ? 'text-white/70' : 'text-base-content/50' }}">
                                                {{ $cell['orders'] }} ord
                                            </div>
                                        @elseif ($metric === 'orders')
                                            <div class="mt-0.5 text-[10px] {{ $textLight ? 'text-white/70' : 'text-base-content/50' }}">
                                                {{ $cell['customers'] }} cust
                                            </div>
                                        @elseif ($metric === 'customers')
                                            <div class="mt-0.5 text-[10px] {{ $textLight ? 'text-white/70' : 'text-base-content/50' }}">
                                                {{ $cell['orders'] }} ord
                                            </div>
                                        @else
                                            <div class="mt-0.5 text-[10px] {{ $textLight ? 'text-white/70' : 'text-base-content/50' }}">
                                                {{ $cell['orders'] }} ord
                                            </div>
                                        @endif
                                    </div>
                                @else
                                    <div class="px-2 py-2 text-base-content/20">—</div>
                                @endif
                            </td>
                        @endforeach

                        {{-- Zone row total --}}
                        @php
                            $zt    = $zoneTotals[$zone];
                            $ztVal = $totalValue($zt);
                        @endphp
                        <td class="rounded-lg bg-base-200 px-2 py-2 text-center font-semibold tabular-nums text-base-content/80">
                            <div>{{ $formatValue($ztVal) }}</div>
                            @if ($metric === 'revenue')
                                <div class="text-[10px] text-base-content/40">{{ $zt['orders'] }} ord</div>
                            @elseif ($metric !== 'customers')
                                <div class="text-[10px] text-base-content/40">{{ $zt['customers'] }} cust</div>
                            @endif
                        </td>
                    </tr>
                @endforeach

                {{-- Area totals footer row --}}
                <tr>
                    <td class="sticky left-0 z-10 rounded-lg bg-base-300 px-3 py-2 font-semibold text-base-content/70">
                        Total
                    </td>
                    @foreach ($areas as $area)
                        @php
                            $at    = $areaTotals[$area];
                            $atVal = $totalValue($at);
                        @endphp
                        <td class="rounded-lg bg-base-200 px-2 py-2 text-center font-semibold tabular-nums text-base-content/80">
                            <div>{{ $formatValue($atVal) }}</div>
                            @if ($metric === 'revenue')
                                <div class="text-[10px] text-base-content/40">{{ $at['orders'] }} ord</div>
                            @elseif ($metric !== 'customers')
                                <div class="text-[10px] text-base-content/40">{{ $at['customers'] }} cust</div>
                            @endif
                        </td>
                    @endforeach

                    {{-- Grand total corner --}}
                    <td class="rounded-lg bg-base-300 px-2 py-2 text-center font-bold tabular-nums text-base-content">
                        <div>{{ $formatValue($grandValue) }}</div>
                        @if ($metric === 'revenue')
                            <div class="text-[10px] text-base-content/50">{{ $grandTotal['orders'] }} ord</div>
                        @endif
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</x-tallui-card>

{{-- Zone breakdown bar chart --}}
<div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">

    <x-tallui-card title="Revenue by Zone" subtitle="Sorted by total revenue." icon="o-chart-bar" :shadow="true">
        @php
            $sortedZones = collect($zoneTotals)->sortByDesc('revenue')->all();
            $zoneMax = max(1, (float) collect($sortedZones)->max('revenue'));
        @endphp
        <div class="space-y-2">
            @foreach ($sortedZones as $zoneName => $zt)
                @php $pct = (int) round($zt['revenue'] / $zoneMax * 100); @endphp
                <div class="flex items-center gap-2 text-xs">
                    <div class="w-24 truncate font-medium text-base-content/80" title="{{ $zoneName }}">{{ $zoneName }}</div>
                    <div class="flex-1 rounded-full bg-base-200" style="height: 8px">
                        <div class="h-full rounded-full transition-all" style="width: {{ $pct }}%; background: rgba({{ $r }},{{ $g }},{{ $b }}, 0.75)"></div>
                    </div>
                    <div class="w-28 text-right tabular-nums text-base-content/70">
                        {{ number_format($zt['revenue'], 0) }} BDT
                        <span class="text-base-content/40">({{ $zt['orders'] }})</span>
                    </div>
                </div>
            @endforeach
        </div>
    </x-tallui-card>

    <x-tallui-card title="Revenue by Area" subtitle="Sorted by total revenue." icon="o-chart-bar" :shadow="true">
        @php
            $sortedAreas = collect($areaTotals)->sortByDesc('revenue')->all();
            $areaMax = max(1, (float) collect($sortedAreas)->max('revenue'));
        @endphp
        <div class="space-y-2">
            @foreach ($sortedAreas as $areaName => $at)
                @php $pct = (int) round($at['revenue'] / $areaMax * 100); @endphp
                <div class="flex items-center gap-2 text-xs">
                    <div class="w-24 truncate font-medium text-base-content/80" title="{{ $areaName }}">{{ $areaName }}</div>
                    <div class="flex-1 rounded-full bg-base-200" style="height: 8px">
                        <div class="h-full rounded-full transition-all" style="width: {{ $pct }}%; background: rgba({{ $r }},{{ $g }},{{ $b }}, 0.75)"></div>
                    </div>
                    <div class="w-28 text-right tabular-nums text-base-content/70">
                        {{ number_format($at['revenue'], 0) }} BDT
                        <span class="text-base-content/40">({{ $at['orders'] }})</span>
                    </div>
                </div>
            @endforeach
        </div>
    </x-tallui-card>

</div>
@endif

</div>

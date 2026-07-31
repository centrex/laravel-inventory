<div>
<x-tallui-card
    title="Sales Order Trend"
    :subtitle="$salesTrend['this_month']['label'] . ' vs ' . $salesTrend['prev_month']['label'] . ' · ' . $salesTrend['scope_label']"
    icon="o-arrow-trending-up"
    :shadow="true"
    class="mb-6"
>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4 mb-5">
        @php
            $trendMetrics = [
                ['key' => 'orders_count', 'label' => 'Orders',  'format' => 'int'],
                ['key' => 'revenue',      'label' => 'Revenue', 'format' => 'currency'],
                ['key' => 'net_profit',   'label' => 'Net Profit', 'format' => 'currency'],
            ];
        @endphp
        @foreach ($trendMetrics as $metric)
            @php
                $cur  = $salesTrend['this_month'][$metric['key']];
                $prev = $salesTrend['prev_month'][$metric['key']];
                $chg  = $salesTrend['change'][$metric['key']];
                $fmt  = fn ($v) => $metric['format'] === 'int'
                    ? number_format((int) $v)
                    : number_format((float) $v, 2);
            @endphp
            <div class="rounded-2xl border border-base-200 bg-base-100 p-4">
                <div class="text-xs font-semibold text-base-content/50 uppercase mb-2">{{ $metric['label'] }}</div>
                <div class="flex items-end justify-between gap-2">
                    <div>
                        <div class="text-2xl font-bold text-base-content">{{ $fmt($cur) }}</div>
                        <div class="text-xs text-base-content/50 mt-0.5">{{ $salesTrend['this_month']['label'] }}</div>
                    </div>
                    <div class="text-right">
                        <div class="text-sm font-medium text-base-content/60">{{ $fmt($prev) }}</div>
                        <div class="text-xs text-base-content/50 mt-0.5">{{ $salesTrend['prev_month']['label'] }}</div>
                    </div>
                </div>
                @if ($metric['key'] === 'net_profit')
                    <div class="mt-1 text-xs text-base-content/50">
                        Margin {{ $salesTrend['this_month']['net_margin_pct'] !== null ? number_format((float) $salesTrend['this_month']['net_margin_pct'], 1) . '%' : '—' }}
                        vs {{ $salesTrend['prev_month']['net_margin_pct'] !== null ? number_format((float) $salesTrend['prev_month']['net_margin_pct'], 1) . '%' : '—' }}
                        · net of COGS, discounts &amp; delivery charges, fulfilled orders only
                    </div>
                @endif
                @if ($chg !== null)
                    <div class="mt-3">
                        @if ($chg > 0)
                            <span class="inline-flex items-center gap-1 text-xs font-semibold text-success">
                                <x-heroicon-m-arrow-trending-up class="w-3.5 h-3.5" /> +{{ $chg }}%
                            </span>
                        @elseif ($chg < 0)
                            <span class="inline-flex items-center gap-1 text-xs font-semibold text-error">
                                <x-heroicon-m-arrow-trending-down class="w-3.5 h-3.5" /> {{ $chg }}%
                            </span>
                        @else
                            <span class="text-xs text-base-content/40">No change</span>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach

        <div class="rounded-2xl border border-base-200 bg-base-100 p-4">
            <div class="text-xs font-semibold text-base-content/50 uppercase mb-2">Dispatched (Live)</div>
            <div class="flex items-end gap-2">
                <div class="text-2xl font-bold text-base-content">{{ number_format($salesTrend['dispatched_count']) }}</div>
                <div class="text-xs text-base-content/50 mb-1">orders in transit</div>
            </div>
            <div class="mt-3">
                <span class="inline-flex items-center gap-1 text-xs font-semibold text-info">
                    <x-heroicon-m-truck class="w-3.5 h-3.5" /> With courier, not yet delivered
                </span>
            </div>
        </div>
    </div>

    @if (!empty($salesTrend['chart']['categories']))
        <livewire:tallui-bar-chart
            :series="$salesTrend['chart']['series']"
            :categories="$salesTrend['chart']['categories']"
            :height="200"
        />
    @endif
</x-tallui-card>
</div>

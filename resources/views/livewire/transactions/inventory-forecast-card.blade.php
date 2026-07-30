<div>
<div class="stats shadow w-full mb-6">
    <x-tallui-stat
        title="Forecast Demand"
        :value="number_format((float) data_get($forecast, 'summary.forecast_qty', 0), 2)"
        :desc="data_get($forecast, 'window.forecast_days', 90) . ' day projected quantity'"
        icon="o-arrow-trending-up"
    />
    <x-tallui-stat
        title="Holistic Requirement"
        :value="number_format((float) data_get($forecast, 'summary.required_qty', 0), 2)"
        :desc="data_get($forecast, 'summary.products_at_risk', 0) . ' products need replenishment'"
        icon="o-cube"
    />
    <x-tallui-stat
        title="Forecast Cash Net"
        :value="number_format((float) data_get($forecast, 'summary.forecast_cash_net', 0), 2)"
        desc="Projected collections less procurement cash"
        icon="o-presentation-chart-line"
    />
</div>

<div class="grid grid-cols-1 gap-4 xl:grid-cols-3 mb-6">
    <x-tallui-card
        title="Sales Forecast"
        subtitle="Projected quantity, revenue, and cash impact from recent order behavior."
        icon="o-arrow-trending-up"
        :shadow="true"
    >
        <div class="space-y-2 text-sm">
            <div class="flex justify-between"><span class="text-base-content/60">Lookback Window</span><strong>{{ data_get($forecast, 'window.lookback_days', 0) }} days</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Forecast Horizon</span><strong>{{ data_get($forecast, 'window.forecast_days', 0) }} days</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Projected Quantity</span><strong>{{ number_format((float) data_get($forecast, 'summary.forecast_qty', 0), 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Projected Revenue</span><strong>{{ number_format((float) data_get($forecast, 'summary.forecast_revenue', 0), 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Expected Cash In</span><strong class="text-success">{{ number_format((float) data_get($forecast, 'summary.forecast_cash_in', 0), 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Expected Cash Out</span><strong>{{ number_format((float) data_get($forecast, 'summary.forecast_cash_out', 0), 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Net Cash</span><strong class="{{ (float) data_get($forecast, 'summary.forecast_cash_net', 0) >= 0 ? 'text-success' : 'text-error' }}">{{ number_format((float) data_get($forecast, 'summary.forecast_cash_net', 0), 2) }}</strong></div>
        </div>
    </x-tallui-card>

    <x-tallui-card
        title="Top Product Risks"
        subtitle="Products with the biggest upcoming demand gap and stockout timeline."
        icon="o-exclamation-triangle"
        :shadow="true"
        class="xl:col-span-2"
    >
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                        <th>Product</th>
                        <th>Forecast Qty</th>
                        <th>Available Soon</th>
                        <th>Gap</th>
                        <th>Cover</th>
                        <th>Stockout</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (collect(data_get($forecast, 'products', []))->take(6) as $product)
                        <tr class="even:bg-base-200/50 hover:bg-base-200">
                            <td>
                                <div class="font-medium">{{ $product['product_name'] }}</div>
                                <div class="text-xs text-base-content/50">{{ $product['sku'] ?: '—' }}</div>
                            </td>
                            <td>{{ number_format((float) $product['forecast_qty'], 2) }}</td>
                            <td>{{ number_format((float) $product['available_soon_qty'], 2) }}</td>
                            <td class="{{ (float) $product['forecast_gap_qty'] > 0 ? 'text-warning font-semibold' : 'text-success' }}">{{ number_format((float) $product['forecast_gap_qty'], 2) }}</td>
                            <td>{{ $product['days_of_cover'] !== null ? number_format((float) $product['days_of_cover'], 1) . ' days' : '—' }}</td>
                            <td>{{ $product['stockout_date'] ?: 'Covered' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-sm text-base-content/60">No forecast data available yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-tallui-card>
</div>

<div class="grid grid-cols-1 gap-4 xl:grid-cols-2 mb-6">
    <x-tallui-card
        title="Customer Forecast"
        subtitle="Projected customer-wise demand based on recent order patterns."
        icon="o-user-group"
        :shadow="true"
    >
        <div class="space-y-3 text-sm">
            @forelse (data_get($forecast, 'customers', []) as $customer)
                <div class="flex items-center justify-between gap-3 rounded-xl border border-base-200 bg-base-100 p-3">
                    <div>
                        <div class="font-medium">{{ $customer['customer_name'] }}</div>
                        <div class="text-xs text-base-content/60">{{ $customer['zone'] ?? 'Unassigned' }} · {{ $customer['area'] ?? 'Unassigned' }} · {{ $customer['demographic'] ?? 'Unassigned' }} · {{ $customer['segment'] ?? 'New' }}</div>
                        <div class="text-xs text-base-content/60">{{ $customer['orders_count'] }} orders · {{ $customer['products_count'] }} products</div>
                    </div>
                    <div class="text-right">
                        <div class="font-semibold">{{ number_format((float) $customer['forecast_revenue'], 2) }}</div>
                        <div class="text-xs text-base-content/60">{{ number_format((float) $customer['forecast_qty'], 2) }} qty</div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-base-content/60">No customer forecast available yet.</p>
            @endforelse
        </div>
    </x-tallui-card>

    <x-tallui-card
        title="Forecast Timeline"
        subtitle="Holistic monthly demand and cash requirement for inventory planning."
        icon="o-calendar-days"
        :shadow="true"
    >
        <div class="space-y-3 text-sm">
            @forelse (data_get($forecast, 'timeline.categories', []) as $index => $month)
                <div class="rounded-xl border border-base-200 bg-base-100 p-3">
                    <div class="flex items-center justify-between gap-3">
                        <div class="font-medium">{{ $month }}</div>
                        <div class="text-xs text-base-content/60">Forecast bucket</div>
                    </div>
                    <div class="mt-2 grid grid-cols-2 gap-2 text-xs md:grid-cols-5">
                        <div><span class="text-base-content/50">Qty</span><div class="font-semibold">{{ number_format((float) data_get($forecast, "timeline.series.0.data.$index", 0), 2) }}</div></div>
                        <div><span class="text-base-content/50">Revenue</span><div class="font-semibold">{{ number_format((float) data_get($forecast, "timeline.series.1.data.$index", 0), 2) }}</div></div>
                        <div><span class="text-base-content/50">Cash In</span><div class="font-semibold text-success">{{ number_format((float) data_get($forecast, "timeline.series.2.data.$index", 0), 2) }}</div></div>
                        <div><span class="text-base-content/50">Cash Out</span><div class="font-semibold">{{ number_format((float) data_get($forecast, "timeline.series.3.data.$index", 0), 2) }}</div></div>
                        <div><span class="text-base-content/50">Net</span><div class="font-semibold {{ (float) data_get($forecast, "timeline.series.4.data.$index", 0) >= 0 ? 'text-success' : 'text-error' }}">{{ number_format((float) data_get($forecast, "timeline.series.4.data.$index", 0), 2) }}</div></div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-base-content/60">No timeline forecast available yet.</p>
            @endforelse
        </div>
    </x-tallui-card>
</div>
</div>

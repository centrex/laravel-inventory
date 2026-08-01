<div>
<div class="stats shadow w-full mb-6">
    <x-tallui-stat title="Forecast Qty" :value="number_format((float) data_get($forecast, 'summary.forecast_qty', 0), 2)" :desc="data_get($forecast, 'window.forecast_days', 0) . ' day demand projection'" icon="o-arrow-trending-up" />
    <x-tallui-stat title="Forecast Revenue" :value="number_format((float) data_get($forecast, 'summary.forecast_revenue', 0), 2)" icon="o-banknotes" />
    <x-tallui-stat title="Product Requirement" :value="number_format((float) data_get($forecast, 'summary.required_qty', 0), 2)" :desc="data_get($forecast, 'summary.products_at_risk', 0) . ' shortage risks'" icon="o-cube" />
    <x-tallui-stat title="Forecast Cash Net" :value="number_format((float) data_get($forecast, 'summary.forecast_cash_net', 0), 2)" :desc="'In ' . number_format((float) data_get($forecast, 'summary.forecast_cash_in', 0), 2) . ' · Out ' . number_format((float) data_get($forecast, 'summary.forecast_cash_out', 0), 2)" icon="o-presentation-chart-line" />
</div>

<div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
    <x-tallui-card title="Demand Forecast" subtitle="Forecast quantity, revenue, timeline, and holistic procurement requirement." icon="o-arrow-trending-up" :shadow="true">
        <div class="space-y-2 text-sm">
            <div class="flex justify-between"><span class="text-base-content/60">History Window</span><strong>{{ data_get($forecast, 'window.lookback_days', 0) }} days</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Forecast Horizon</span><strong>{{ data_get($forecast, 'window.forecast_days', 0) }} days</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Projected Quantity</span><strong>{{ number_format((float) data_get($forecast, 'summary.forecast_qty', 0), 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Projected Revenue</span><strong>{{ number_format((float) data_get($forecast, 'summary.forecast_revenue', 0), 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Replenishment Qty</span><strong>{{ number_format((float) data_get($forecast, 'summary.required_qty', 0), 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Replenishment Cost</span><strong>{{ number_format((float) data_get($forecast, 'summary.required_procurement_cost', 0), 2) }}</strong></div>
        </div>
    </x-tallui-card>

    <x-tallui-card title="Cashflow Forecast" subtitle="Inventory-driven inflow and procurement outflow for the upcoming timeline." icon="o-banknotes" :shadow="true">
        <div class="space-y-2 text-sm">
            <div class="flex justify-between"><span class="text-base-content/60">Collection Ratio</span><strong>{{ number_format((float) data_get($forecast, 'summary.collection_ratio', 0), 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Supplier Payment Ratio</span><strong>{{ number_format((float) data_get($forecast, 'summary.supplier_payment_ratio', 0), 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Forecast Cash In</span><strong class="text-success">{{ number_format((float) data_get($forecast, 'summary.forecast_cash_in', 0), 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Forecast Cash Out</span><strong>{{ number_format((float) data_get($forecast, 'summary.forecast_cash_out', 0), 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Net Cash</span><strong class="{{ (float) data_get($forecast, 'summary.forecast_cash_net', 0) >= 0 ? 'text-success' : 'text-error' }}">{{ number_format((float) data_get($forecast, 'summary.forecast_cash_net', 0), 2) }}</strong></div>
        </div>
    </x-tallui-card>

    <x-tallui-card title="Timeline" subtitle="Monthly forecast buckets for quantity and cash movement." icon="o-calendar-days" :shadow="true">
        <div class="space-y-3 text-sm">
            @forelse (data_get($forecast, 'timeline.categories', []) as $index => $month)
                <div class="rounded-xl border border-base-200 bg-base-100 p-3">
                    <div class="font-medium">{{ $month }}</div>
                    <div class="mt-2 grid grid-cols-2 gap-2 text-xs">
                        <div><span class="text-base-content/50">Qty</span><div class="font-semibold">{{ number_format((float) data_get($forecast, "timeline.series.0.data.$index", 0), 2) }}</div></div>
                        <div><span class="text-base-content/50">Revenue</span><div class="font-semibold">{{ number_format((float) data_get($forecast, "timeline.series.1.data.$index", 0), 2) }}</div></div>
                        <div><span class="text-base-content/50">Cash In</span><div class="font-semibold text-success">{{ number_format((float) data_get($forecast, "timeline.series.2.data.$index", 0), 2) }}</div></div>
                        <div><span class="text-base-content/50">Cash Out</span><div class="font-semibold">{{ number_format((float) data_get($forecast, "timeline.series.3.data.$index", 0), 2) }}</div></div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-base-content/60">No timeline forecast available.</p>
            @endforelse
        </div>
    </x-tallui-card>
</div>
</div>

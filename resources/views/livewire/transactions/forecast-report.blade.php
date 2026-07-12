<div>
<x-tallui-page-header title="Sales Forecast" subtitle="Demand projection, cashflow outlook, and procurement requirement." icon="o-arrow-trending-up">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Reports', 'href' => route('inventory.reports.index')],
            ['label' => 'Forecast'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <div class="flex flex-wrap items-center gap-2">
            <x-tallui-select wire:model.live="lookbackDays" class="select-sm">
                <option value="30">30 day history</option>
                <option value="90">90 day history</option>
                <option value="180">180 day history</option>
                <option value="365">365 day history</option>
            </x-tallui-select>
            <x-tallui-select wire:model.live="forecastDays" class="select-sm">
                <option value="30">30 day horizon</option>
                <option value="90">90 day horizon</option>
                <option value="180">180 day horizon</option>
            </x-tallui-select>
        </div>
    </x-slot:actions>
</x-tallui-page-header>

<div class="stats shadow w-full mb-6">
    <x-tallui-stat title="Forecast Qty" :value="number_format((float) data_get($forecast, 'summary.forecast_qty', 0), 2)" :desc="data_get($forecast, 'window.forecast_days', 0) . ' day demand projection'" icon="o-arrow-trending-up" />
    <x-tallui-stat title="Forecast Revenue" :value="number_format((float) data_get($forecast, 'summary.forecast_revenue', 0), 2)" icon="o-banknotes" />
    <x-tallui-stat title="Product Requirement" :value="number_format((float) data_get($forecast, 'summary.required_qty', 0), 2)" :desc="data_get($forecast, 'summary.products_at_risk', 0) . ' shortage risks'" icon="o-cube" />
    <x-tallui-stat title="Forecast Cash Net" :value="number_format((float) data_get($forecast, 'summary.forecast_cash_net', 0), 2)" :desc="'In ' . number_format((float) data_get($forecast, 'summary.forecast_cash_in', 0), 2) . ' · Out ' . number_format((float) data_get($forecast, 'summary.forecast_cash_out', 0), 2)" icon="o-presentation-chart-line" />
</div>

<div class="grid grid-cols-1 gap-4 xl:grid-cols-3 mb-6">
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

<div class="grid grid-cols-1 gap-4 xl:grid-cols-2 mb-6">
    <x-tallui-card title="Product Forecast" subtitle="Product quantity forecast, stockout timing, and holistic requirement gap." icon="o-cube" :shadow="true">
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                        <th>Product</th>
                        <th>Forecast</th>
                        <th>Available Soon</th>
                        <th>Requirement</th>
                        <th>Timeline</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (data_get($forecast, 'products', []) as $product)
                        <tr class="even:bg-base-200/50 hover:bg-base-200">
                            <td>
                                <div class="font-medium">{{ $product['product_name'] }}</div>
                                <div class="text-xs text-base-content/60">{{ $product['sku'] ?: '—' }}</div>
                            </td>
                            <td>
                                <div>{{ number_format((float) $product['forecast_qty'], 2) }}</div>
                                <div class="text-xs text-base-content/60">{{ number_format((float) $product['forecast_revenue'], 2) }}</div>
                            </td>
                            <td>
                                <div>{{ number_format((float) $product['available_soon_qty'], 2) }}</div>
                                <div class="text-xs text-base-content/60">cover {{ $product['days_of_cover'] !== null ? number_format((float) $product['days_of_cover'], 1) . 'd' : '—' }}</div>
                            </td>
                            <td class="{{ (float) $product['forecast_gap_qty'] > 0 ? 'text-warning font-semibold' : 'text-success' }}">
                                <div>{{ number_format((float) $product['forecast_gap_qty'], 2) }}</div>
                                <div class="text-xs text-base-content/60">{{ number_format((float) $product['forecast_procurement_cost'], 2) }}</div>
                            </td>
                            <td>{{ $product['stockout_date'] ?: 'Covered' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-sm text-base-content/60">No product forecast data available.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-tallui-card>

    <x-tallui-card title="Customer Forecast" subtitle="Customer-wise projected quantity and revenue." icon="o-user-group" :shadow="true">
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                        <th>Customer</th>
                        <th>Zone</th>
                        <th>Area</th>
                        <th>Demography</th>
                        <th>Segment</th>
                        <th>Orders</th>
                        <th>Products</th>
                        <th>Forecast Qty</th>
                        <th>Forecast Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (data_get($forecast, 'customers', []) as $customer)
                        <tr class="even:bg-base-200/50 hover:bg-base-200">
                            <td>{{ $customer['customer_name'] }}</td>
                            <td>{{ $customer['zone'] ?? 'Unassigned' }}</td>
                            <td>{{ $customer['area'] ?? 'Unassigned' }}</td>
                            <td>{{ $customer['demographic'] ?? 'Unassigned' }}</td>
                            <td>{{ $customer['segment'] ?? 'New' }}</td>
                            <td>{{ $customer['orders_count'] }}</td>
                            <td>{{ $customer['products_count'] }}</td>
                            <td>{{ number_format((float) $customer['forecast_qty'], 2) }}</td>
                            <td>{{ number_format((float) $customer['forecast_revenue'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-sm text-base-content/60">No customer forecast data available.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-tallui-card>
</div>

<div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
    <x-tallui-card title="Zone Forecast" subtitle="Zone-wise customer segment, demand, and revenue projection." icon="o-map" :shadow="true">
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                        <th>Zone</th>
                        <th>Segment</th>
                        <th>Customers</th>
                        <th>Orders</th>
                        <th>Forecast Qty</th>
                        <th>Forecast Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (data_get($forecast, 'zones', []) as $zone)
                        <tr class="even:bg-base-200/50 hover:bg-base-200">
                            <td>{{ $zone['zone'] ?? 'Unassigned' }}</td>
                            <td>{{ $zone['segment'] ?? 'New' }}</td>
                            <td>{{ $zone['customers_count'] }}</td>
                            <td>{{ $zone['orders_count'] }}</td>
                            <td>{{ number_format((float) $zone['forecast_qty'], 2) }}</td>
                            <td>{{ number_format((float) $zone['forecast_revenue'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-sm text-base-content/60">No zone forecast data available.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-tallui-card>

    <x-tallui-card title="Area Forecast" subtitle="Area-wise customer segment, demand, and revenue projection." icon="o-map-pin" :shadow="true">
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                        <th>Area</th>
                        <th>Segment</th>
                        <th>Customers</th>
                        <th>Orders</th>
                        <th>Forecast Qty</th>
                        <th>Forecast Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (data_get($forecast, 'areas', []) as $area)
                        <tr class="even:bg-base-200/50 hover:bg-base-200">
                            <td>{{ $area['area'] ?? 'Unassigned' }}</td>
                            <td>{{ $area['segment'] ?? 'New' }}</td>
                            <td>{{ $area['customers_count'] }}</td>
                            <td>{{ $area['orders_count'] }}</td>
                            <td>{{ number_format((float) $area['forecast_qty'], 2) }}</td>
                            <td>{{ number_format((float) $area['forecast_revenue'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-sm text-base-content/60">No area forecast data available.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-tallui-card>

    <x-tallui-card title="Demography Forecast" subtitle="Demography-wise customer segment, demand, and revenue projection." icon="o-identification" :shadow="true">
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                        <th>Demography</th>
                        <th>Segment</th>
                        <th>Customers</th>
                        <th>Orders</th>
                        <th>Forecast Qty</th>
                        <th>Forecast Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (data_get($forecast, 'demographics', []) as $demographic)
                        <tr class="even:bg-base-200/50 hover:bg-base-200">
                            <td>{{ $demographic['demographic_segment'] ?? 'Unassigned' }}</td>
                            <td>{{ $demographic['segment'] ?? 'New' }}</td>
                            <td>{{ $demographic['customers_count'] }}</td>
                            <td>{{ $demographic['orders_count'] }}</td>
                            <td>{{ number_format((float) $demographic['forecast_qty'], 2) }}</td>
                            <td>{{ number_format((float) $demographic['forecast_revenue'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-sm text-base-content/60">No demographic forecast data available.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-tallui-card>
</div>
</div>

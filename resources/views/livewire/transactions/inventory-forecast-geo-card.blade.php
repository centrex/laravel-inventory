<div>
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

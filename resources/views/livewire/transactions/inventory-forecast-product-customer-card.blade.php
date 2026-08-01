<div>
<div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
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
</div>

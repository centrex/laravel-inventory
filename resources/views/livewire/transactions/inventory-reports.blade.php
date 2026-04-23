<div>
<x-tallui-page-header title="Inventory Reports" subtitle="Operational sales, purchase, and payment reporting aligned with the legacy Octa workflow." icon="o-chart-bar">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Reports'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-tallui-input type="date" wire:model.live="startDate" class="input-sm" />
            <x-tallui-input type="date" wire:model.live="endDate" class="input-sm" />
        </div>
    </x-slot:actions>
</x-tallui-page-header>

<div class="stats shadow w-full mb-6">
    <x-tallui-stat title="Sales Net" :value="number_format($salesMetrics['net_total'], 2)" :desc="'Discount ' . number_format($salesMetrics['discount'], 2)" icon="o-shopping-cart" />
    <x-tallui-stat title="Purchases Net" :value="number_format($purchaseMetrics['net_total'], 2)" :desc="'Shipping ' . number_format($purchaseMetrics['shipping'], 2)" icon="o-arrow-down-tray" />
    <x-tallui-stat title="Collections" :value="number_format($paymentMetrics['collections'], 2)" :desc="'Supplier pay ' . number_format($paymentMetrics['supplier_payments'], 2)" icon="o-banknotes" />
    <x-tallui-stat title="Forecast Qty" :value="number_format((float) data_get($forecast, 'summary.forecast_qty', 0), 2)" :desc="data_get($forecast, 'window.forecast_days', 0) . ' day demand projection'" icon="o-arrow-trending-up" />
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

<div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
    <x-tallui-card title="Sales Report" subtitle="Octa-style sales totals, discount, tax, paid, and due." icon="o-shopping-cart" :shadow="true">
        <div class="space-y-2 text-sm">
            <div class="flex justify-between"><span class="text-base-content/60">Orders</span><strong>{{ $salesMetrics['count'] }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Gross Subtotal</span><strong>{{ number_format($salesMetrics['gross_subtotal'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Discount</span><strong>{{ number_format($salesMetrics['discount'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Tax</span><strong>{{ number_format($salesMetrics['tax'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Net Total</span><strong>{{ number_format($salesMetrics['net_total'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Fulfilled Value</span><strong>{{ number_format($salesMetrics['fulfilled_total'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Collected</span><strong class="text-success">{{ number_format($salesMetrics['invoice_paid'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Due</span><strong class="{{ $salesMetrics['invoice_due'] > 0 ? 'text-warning' : 'text-success' }}">{{ number_format($salesMetrics['invoice_due'], 2) }}</strong></div>
        </div>
        <div class="mt-4 flex flex-wrap gap-2 text-xs">
            @foreach ($salesMetrics['status_counts'] as $status => $count)
                <x-tallui-badge type="primary">{{ ucfirst(str_replace('_', ' ', $status)) }}: {{ $count }}</x-tallui-badge>
            @endforeach
        </div>
        <div class="mt-5 space-y-3 border-t border-base-200 pt-4 text-sm">
            @forelse ($saleOrders as $order)
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="font-medium">{{ $order->so_number }}</div>
                        <div class="text-xs text-base-content/60">{{ $order->customer?->name ?? 'Walk-in' }} · {{ $order->ordered_at?->format('M d, Y') ?? '—' }}</div>
                    </div>
                    <div class="text-right">
                        <div>{{ number_format((float) $order->total_local, 2) }}</div>
                        <div class="text-xs text-base-content/60">Disc {{ number_format((float) $order->discount_local, 2) }}</div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-base-content/60">No sale orders found for this period.</p>
            @endforelse
        </div>
    </x-tallui-card>

    <x-tallui-card title="Purchase Report" subtitle="Octa-style purchase totals, shipping, paid, and due." icon="o-arrow-down-tray" :shadow="true">
        <div class="space-y-2 text-sm">
            <div class="flex justify-between"><span class="text-base-content/60">Orders</span><strong>{{ $purchaseMetrics['count'] }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Gross Subtotal</span><strong>{{ number_format($purchaseMetrics['gross_subtotal'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Tax</span><strong>{{ number_format($purchaseMetrics['tax'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Shipping</span><strong>{{ number_format($purchaseMetrics['shipping'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Other Charges</span><strong>{{ number_format($purchaseMetrics['other_charges'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Net Total</span><strong>{{ number_format($purchaseMetrics['net_total'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Received Value</span><strong>{{ number_format($purchaseMetrics['received_total'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Paid</span><strong class="text-success">{{ number_format($purchaseMetrics['bill_paid'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Due</span><strong class="{{ $purchaseMetrics['bill_due'] > 0 ? 'text-warning' : 'text-success' }}">{{ number_format($purchaseMetrics['bill_due'], 2) }}</strong></div>
        </div>
        <div class="mt-4 flex flex-wrap gap-2 text-xs">
            @foreach ($purchaseMetrics['status_counts'] as $status => $count)
                <x-tallui-badge type="info">{{ ucfirst(str_replace('_', ' ', $status)) }}: {{ $count }}</x-tallui-badge>
            @endforeach
        </div>
        <div class="mt-5 space-y-3 border-t border-base-200 pt-4 text-sm">
            @forelse ($purchaseOrders as $order)
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="font-medium">{{ $order->po_number }}</div>
                        <div class="text-xs text-base-content/60">{{ $order->supplier?->name ?? '—' }} · {{ $order->ordered_at?->format('M d, Y') ?? '—' }}</div>
                    </div>
                    <div class="text-right">
                        <div>{{ number_format((float) $order->total_local, 2) }}</div>
                        <div class="text-xs text-base-content/60">Ship {{ number_format((float) $order->shipping_local, 2) }}</div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-base-content/60">No purchase orders found for this period.</p>
            @endforelse
        </div>
    </x-tallui-card>

    <x-tallui-card title="Payment Report" subtitle="Customer collections and supplier payments, separated like the old Octa cash view." icon="o-banknotes" :shadow="true">
        @if ($paymentMetrics['available'])
            <div class="space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-base-content/60">Payment Rows</span><strong>{{ $paymentMetrics['count'] }}</strong></div>
                <div class="flex justify-between"><span class="text-base-content/60">Customer Collections</span><strong class="text-success">{{ number_format($paymentMetrics['collections'], 2) }}</strong></div>
                <div class="flex justify-between"><span class="text-base-content/60">Supplier Payments</span><strong>{{ number_format($paymentMetrics['supplier_payments'], 2) }}</strong></div>
            </div>
            <div class="mt-5 space-y-3 border-t border-base-200 pt-4 text-sm">
                @forelse ($paymentMetrics['recent'] as $payment)
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <div class="font-medium">{{ $payment->payment_number }}</div>
                            <div class="text-xs text-base-content/60">
                                {{ class_basename((string) $payment->payable_type) }} · {{ $payment->payment_date?->format('M d, Y') ?? '—' }}
                            </div>
                        </div>
                        <div class="text-right">
                            <div>{{ number_format((float) $payment->amount, 2) }}</div>
                            <div class="text-xs text-base-content/60">{{ $payment->payment_method ?? '—' }}</div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-base-content/60">No payments found for this period.</p>
                @endforelse
            </div>
        @else
            <p class="text-sm text-base-content/60">Accounting payments are not available, so only inventory-side order totals can be shown.</p>
        @endif
    </x-tallui-card>
</div>

<div class="grid grid-cols-1 gap-4 xl:grid-cols-2 mt-6">
    <x-tallui-card title="Product Forecast" subtitle="Product quantity forecast, stockout timing, and holistic requirement gap." icon="o-cube" :shadow="true">
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-50 text-xs uppercase text-base-content/50">
                        <th>Product</th>
                        <th>Forecast</th>
                        <th>Available Soon</th>
                        <th>Requirement</th>
                        <th>Timeline</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (data_get($forecast, 'products', []) as $product)
                        <tr>
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
                    <tr class="bg-base-50 text-xs uppercase text-base-content/50">
                        <th>Customer</th>
                        <th>Orders</th>
                        <th>Products</th>
                        <th>Forecast Qty</th>
                        <th>Forecast Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (data_get($forecast, 'customers', []) as $customer)
                        <tr>
                            <td>{{ $customer['customer_name'] }}</td>
                            <td>{{ $customer['orders_count'] }}</td>
                            <td>{{ $customer['products_count'] }}</td>
                            <td>{{ number_format((float) $customer['forecast_qty'], 2) }}</td>
                            <td>{{ number_format((float) $customer['forecast_revenue'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-sm text-base-content/60">No customer forecast data available.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-tallui-card>
</div>
</div>

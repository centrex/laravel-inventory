<div>
<div class="stats shadow w-full mb-6">
    <x-tallui-stat title="Orders" :value="number_format($salesMetrics['count'])" icon="o-shopping-cart" />
    <x-tallui-stat title="Net Total" :value="number_format($salesMetrics['net_total'], 2)" :desc="'Discount ' . number_format($salesMetrics['discount'], 2)" icon="o-banknotes" />
    <x-tallui-stat title="Collected" :value="number_format($salesMetrics['invoice_paid'], 2)" icon="o-check-circle" icon-color="text-success" />
    <x-tallui-stat title="Due" :value="number_format($salesMetrics['invoice_due'], 2)" icon="o-exclamation-circle" :icon-color="$salesMetrics['invoice_due'] > 0 ? 'text-warning' : 'text-success'" />
    <x-tallui-stat title="Distinct Products" :value="number_format($productCount)" desc="Sold in this period" icon="o-cube" />
</div>

<x-tallui-card title="Sales Totals" subtitle="Gross, discount, tax, shipping, and collection status." icon="o-shopping-cart" :shadow="true" class="mb-6">
    <div class="space-y-2 text-sm">
        <div class="flex justify-between"><span class="text-base-content/60">Orders</span><strong>{{ $salesMetrics['count'] }}</strong></div>
        <div class="flex justify-between"><span class="text-base-content/60">Gross Subtotal</span><strong>{{ number_format($salesMetrics['gross_subtotal'], 2) }}</strong></div>
        <div class="flex justify-between"><span class="text-base-content/60">Discount</span><strong>{{ number_format($salesMetrics['discount'], 2) }}</strong></div>
        <div class="flex justify-between"><span class="text-base-content/60">Tax</span><strong>{{ number_format($salesMetrics['tax'], 2) }}</strong></div>
        <div class="flex justify-between"><span class="text-base-content/60">Shipping</span><strong>{{ number_format($salesMetrics['shipping'], 2) }}</strong></div>
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
</x-tallui-card>

<div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
    <x-tallui-card title="Sales by Employee" subtitle="Revenue, order count, and gross profit for the selected period." icon="o-user-group" :shadow="true">
        @if (!empty($salesByEmployee))
            <div class="overflow-x-auto">
                <table class="table table-sm w-full">
                    <thead>
                        <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                            <th>Employee</th>
                            <th class="text-right">Orders</th>
                            <th class="text-right">Revenue</th>
                            <th class="text-right" title="Net of COGS, discounts &amp; delivery charges — fulfilled orders only">Gross Profit</th>
                            <th class="text-right" title="Computed over fulfilled orders only; unfulfilled order revenue isn't included in this ratio">Margin %</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($salesByEmployee as $employee)
                            <tr class="even:bg-base-200/50 hover:bg-base-200">
                                <td>{{ $employee['name'] }}</td>
                                <td class="text-right">{{ number_format($employee['orders_count']) }}</td>
                                <td class="text-right font-medium">{{ number_format($employee['revenue'], 2) }}</td>
                                <td class="text-right font-medium">{{ number_format($employee['gross_profit'], 2) }}</td>
                                <td class="text-right">{{ $employee['gross_margin_pct'] !== null ? number_format($employee['gross_margin_pct'], 1) . '%' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <x-tallui-empty-state title="No sales in this period" description="Employee breakdown will appear once orders are placed." icon="o-user-group" size="sm" />
        @endif
    </x-tallui-card>

    <x-tallui-card title="Sales by Price Tier" subtitle="Revenue, order count, and gross profit for the selected period." icon="o-tag" :shadow="true">
        @if (!empty($salesByPriceTier))
            <div class="overflow-x-auto">
                <table class="table table-sm w-full">
                    <thead>
                        <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                            <th>Tier</th>
                            <th class="text-right">Orders</th>
                            <th class="text-right">Revenue</th>
                            <th class="text-right" title="Net of COGS, discounts &amp; delivery charges — fulfilled orders only">Gross Profit</th>
                            <th class="text-right" title="Computed over fulfilled orders only; unfulfilled order revenue isn't included in this ratio">Margin %</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($salesByPriceTier as $tier)
                            <tr class="even:bg-base-200/50 hover:bg-base-200">
                                <td>{{ $tier['label'] }}</td>
                                <td class="text-right">{{ number_format($tier['orders_count']) }}</td>
                                <td class="text-right font-medium">{{ number_format($tier['revenue'], 2) }}</td>
                                <td class="text-right font-medium">{{ number_format($tier['gross_profit'], 2) }}</td>
                                <td class="text-right">{{ $tier['gross_margin_pct'] !== null ? number_format($tier['gross_margin_pct'], 1) . '%' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <x-tallui-empty-state title="No sales in this period" description="Price tier breakdown will appear once orders are placed." icon="o-tag" size="sm" />
        @endif
    </x-tallui-card>
</div>
</div>

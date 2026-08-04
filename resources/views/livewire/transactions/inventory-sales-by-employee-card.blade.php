<div>
<x-tallui-card
    title="Sales by Employee"
    :subtitle="now()->format('M Y') . ' · ' . $scopeLabel"
    icon="o-user-group"
    :shadow="true"
    class="mb-6"
>
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
        <x-tallui-empty-state
            title="No sales this month"
            description="Employee breakdown will appear once orders are placed."
            icon="o-user-group"
            size="sm"
        />
    @endif
</x-tallui-card>
</div>

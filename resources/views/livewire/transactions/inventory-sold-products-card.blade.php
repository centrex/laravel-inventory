<div>
<x-tallui-card title="Sold Products" subtitle="Units sold per product in the selected period, ranked by quantity. Draft and cancelled orders are excluded." icon="o-cube" :shadow="true" padding="none">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                    <th class="pl-5">Product</th>
                    <th class="text-right">Qty Sold</th>
                    <th class="text-right">Revenue</th>
                    <th class="text-right" title="Fulfilled quantity × unit cost">Cost</th>
                    <th class="text-right" title="Fulfilled units only: (qty fulfilled × unit price) − cost">Profit</th>
                    <th class="text-right" title="Computed over fulfilled units only">Margin %</th>
                    <th class="pr-5 text-right">Orders</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse ($soldProducts as $row)
                    <tr class="even:bg-base-200/50 hover:bg-base-200">
                        <td class="pl-5">
                            <div class="font-medium text-sm">{{ $row['name'] }}</div>
                            <div class="text-xs text-base-content/50">{{ $row['sku'] ?: '—' }}</div>
                        </td>
                        <td class="text-right font-mono text-sm font-semibold">{{ number_format($row['qty_sold'], 2) }}</td>
                        <td class="text-right font-mono text-sm">{{ number_format($row['revenue_local'], 2) }}</td>
                        <td class="text-right font-mono text-sm">{{ number_format($row['cost_amount'], 2) }}</td>
                        <td class="text-right font-mono text-sm font-semibold">{{ number_format($row['profit_amount'], 2) }}</td>
                        <td class="text-right text-sm">{{ $row['margin_pct'] !== null ? number_format($row['margin_pct'], 1) . '%' : '—' }}</td>
                        <td class="pr-5 text-right text-sm text-base-content/60">{{ $row['orders_count'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <x-tallui-empty-state title="No products sold" description="No confirmed sale orders in this period yet." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-tallui-card>
</div>

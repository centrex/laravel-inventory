<div>
<x-tallui-card
    title="Sales by Price Tier"
    :subtitle="now()->format('M Y') . ' · ' . $scopeLabel"
    icon="o-tag"
    :shadow="true"
    class="mb-6"
>
    @if (!empty($salesByPriceTier))
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <livewire:tallui-pie-chart
                wire:key="sales-by-price-tier-chart"
                :series="collect($salesByPriceTier)->pluck('revenue')->all()"
                :labels="collect($salesByPriceTier)->pluck('label')->all()"
                :height="220"
            />

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
        </div>
    @else
        <x-tallui-empty-state
            title="No sales this month"
            description="Price tier breakdown will appear once orders are placed."
            icon="o-tag"
            size="sm"
        />
    @endif
</x-tallui-card>
</div>

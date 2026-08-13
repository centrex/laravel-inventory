<div>
<x-tallui-card
    title="Sales by Employee"
    :subtitle="now()->format('M Y') . ' · ' . $scopeLabel . ' · click a row for its price-tier split'"
    icon="o-user-group"
    :shadow="true"
    class="mb-6"
>
    @if (!empty($salesByEmployee))
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                        <th></th>
                        <th>Employee</th>
                        <th class="text-right">Orders</th>
                        <th class="text-right">Revenue</th>
                        <th class="text-right" title="Net of COGS, discounts &amp; delivery charges — fulfilled orders only">Gross Profit</th>
                        <th class="text-right" title="Computed over fulfilled orders only; unfulfilled order revenue isn't included in this ratio">Margin %</th>
                    </tr>
                </thead>
                @foreach ($salesByEmployee as $employee)
                    {{-- One <tbody x-data> per employee so its toggle row and detail row share
                         Alpine state without affecting any other employee's row (sibling <tr>s
                         don't share x-data scope; a <tbody> per pair does, and multiple <tbody>
                         elements in one <table> are valid HTML). --}}
                    <tbody x-data="{ open: false }">
                        <tr @click="open = !open" class="even:bg-base-200/50 hover:bg-base-200 cursor-pointer">
                            <td class="w-6 text-base-content/40">
                                <x-tallui-icon name="o-chevron-right" size="w-3 h-3" x-show="!open" />
                                <x-tallui-icon name="o-chevron-down" size="w-3 h-3" x-show="open" x-cloak />
                            </td>
                            <td>{{ $employee['name'] }}</td>
                            <td class="text-right">{{ number_format($employee['orders_count']) }}</td>
                            <td class="text-right font-medium">{{ number_format($employee['revenue'], 2) }}</td>
                            <td class="text-right font-medium">{{ number_format($employee['gross_profit'], 2) }}</td>
                            <td class="text-right">{{ $employee['gross_margin_pct'] !== null ? number_format($employee['gross_margin_pct'], 1) . '%' : '—' }}</td>
                        </tr>
                        <tr x-show="open" x-cloak>
                            <td colspan="6" class="bg-base-200/40 p-0">
                                <div class="px-4 py-3">
                                    <div class="text-xs font-medium text-base-content/50 uppercase tracking-wide mb-2">Price tier split</div>
                                    @if (!empty($employee['by_price_tier']))
                                        <table class="table table-xs w-full">
                                            <thead>
                                                <tr class="text-xs text-base-content/50">
                                                    <th>Tier</th>
                                                    <th class="text-right">Orders</th>
                                                    <th class="text-right">Value</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($employee['by_price_tier'] as $tier)
                                                    <tr>
                                                        <td>{{ $tier['label'] }}</td>
                                                        <td class="text-right">{{ number_format($tier['orders_count']) }}</td>
                                                        <td class="text-right font-medium">{{ number_format($tier['revenue'], 2) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    @else
                                        <p class="text-xs text-base-content/40">No tier data.</p>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    </tbody>
                @endforeach
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

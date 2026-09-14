<div>
<x-tallui-card title="Returned Products" subtitle="Units returned per product in the selected period, ranked by quantity. Only posted returns are included." icon="o-arrow-uturn-left" :shadow="true" padding="none">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                    <th class="pl-5">Product</th>
                    <th class="text-right">Qty Returned</th>
                    <th class="text-right">Return Value</th>
                    <th class="text-right" title="Returned quantity × unit cost">Cost</th>
                    <th class="pr-5 text-right">Returns</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse ($returnedProducts as $row)
                    <tr class="even:bg-base-200/50 hover:bg-base-200">
                        <td class="pl-5">
                            <div class="font-medium text-sm">{{ $row['name'] }}</div>
                            <div class="text-xs text-base-content/50">{{ $row['sku'] ?: '—' }}</div>
                        </td>
                        <td class="text-right font-mono text-sm font-semibold">{{ number_format($row['qty_returned'], 2) }}</td>
                        <td class="text-right font-mono text-sm">{{ number_format($row['value_local'], 2) }}</td>
                        <td class="text-right font-mono text-sm">{{ number_format($row['cost_amount'], 2) }}</td>
                        <td class="pr-5 text-right text-sm text-base-content/60">{{ $row['returns_count'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            <x-tallui-empty-state title="No products returned" description="No posted sale returns in this period yet." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-tallui-card>
</div>

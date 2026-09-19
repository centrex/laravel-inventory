<div>
<x-tallui-page-header :title="$record->adjustment_number" subtitle="Stock adjustment detail and posted movement lines." icon="o-scale">
    <x-slot:breadcrumbs><x-tallui-breadcrumb :links="[['label' => 'Inventory', 'href' => route('inventory.dashboard')], ['label' => 'Adjustments', 'href' => route('inventory.adjustments.index')], ['label' => $record->adjustment_number]]" /></x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-badge :type="\Centrex\Inventory\Support\StatusBadge::type($record->status)">{{ $record->status->label() }}</x-tallui-badge>
    </x-slot:actions>
</x-tallui-page-header>
<x-tallui-card title="Summary" subtitle="Adjustment context." icon="o-document-text" :shadow="true" class="mb-4">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-sm">
        <div><span class="text-base-content/50">Warehouse</span><div class="font-medium">{{ $record->warehouse?->name ?? '—' }}</div></div>
        <div><span class="text-base-content/50">Reason</span><div class="font-medium">{{ $record->reason?->label() ?? '—' }}</div></div>
        <div><span class="text-base-content/50">Adjusted At</span><div class="font-medium">{{ $record->adjusted_at?->format('M d, Y') ?? '—' }}</div></div>
        <div><span class="text-base-content/50">Notes</span><div class="font-medium">{{ $record->notes ?: '—' }}</div></div>
    </div>
</x-tallui-card>
<x-tallui-card title="Lines" subtitle="System vs. actual quantity for each product." icon="o-queue-list" :shadow="true">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead><tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300"><th>Product</th><th>System Qty</th><th>Actual Qty</th><th>Delta</th><th>Unit Cost</th><th>Notes</th></tr></thead>
            <tbody>
                @foreach($record->items as $item)
                <tr>
                    <td>{{ $item->variant ? trim(($item->product?->name ?? 'Product') . ' / ' . $item->variant->name) : ($item->product?->name ?? 'Product') }}</td>
                    <td>{{ number_format((float) $item->qty_system, 4) }}</td>
                    <td>{{ number_format((float) $item->qty_actual, 4) }}</td>
                    <td class="{{ (float) $item->qty_delta > 0 ? 'text-success' : ((float) $item->qty_delta < 0 ? 'text-error' : '') }}">{{ (float) $item->qty_delta > 0 ? '+' : '' }}{{ number_format((float) $item->qty_delta, 4) }}</td>
                    <td>{{ number_format((float) $item->unit_cost_amount, 2) }}</td>
                    <td>{{ $item->notes ?: '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-tallui-card>
</div>

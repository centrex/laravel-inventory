<div>
<x-tallui-page-header :title="$record->return_number" subtitle="Posted customer return and returned stock lines." icon="o-arrow-uturn-left">
    <x-slot:breadcrumbs><x-tallui-breadcrumb :links="[['label' => 'Inventory', 'href' => route('inventory.dashboard')], ['label' => 'Sale Returns', 'href' => route('inventory.sale-returns.index')], ['label' => $record->return_number]]" /></x-slot:breadcrumbs>
    <x-slot:actions>
        @if (Route::has('accounting.journal'))
            <x-tallui-button
                label="Post Return Journal"
                icon="o-pencil-square"
                :link="route('accounting.journal', ['create' => 1, 'reference' => $record->return_number, 'description' => 'Sale return ' . $record->return_number])"
                class="btn-info btn-sm"
            />
        @endif
    </x-slot:actions>
</x-tallui-page-header>
<x-tallui-card title="Summary" subtitle="Return context and warehouse." icon="o-document-text" :shadow="true" class="mb-4">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-sm">
        <div><span class="text-base-content/50">Customer</span><div class="font-medium">{{ $record->customer?->name ?? '—' }}</div></div>
        <div><span class="text-base-content/50">Warehouse</span><div class="font-medium">{{ $record->warehouse?->name ?? '—' }}</div></div>
        <div><span class="text-base-content/50">Status</span><div class="font-medium">{{ ucfirst((string) $record->status) }}</div></div>
        <div><span class="text-base-content/50">Source</span><div class="font-medium">{{ $record->saleOrder?->so_number ?? 'Manual' }}</div></div>
    </div>
</x-tallui-card>
<x-tallui-card title="Finance" subtitle="Post the accounting side of this return." icon="o-banknotes" :shadow="true" class="mb-4">
    <p class="text-sm text-base-content/60">Inventory has already been updated for this return. Use the posting button to record the accounting journal until automated credit-note posting is added.</p>
</x-tallui-card>
<x-tallui-card title="Lines" subtitle="Products returned into stock." icon="o-queue-list" :shadow="true">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead><tr class="bg-base-50 text-xs uppercase"><th>Product</th><th>Qty</th><th>Unit Price</th><th>Unit Cost</th><th>Line Total</th></tr></thead>
            <tbody>@foreach($record->items as $item)<tr><td>{{ $item->variant ? trim(($item->product?->name ?? 'Product') . ' / ' . $item->variant->name) : ($item->product?->name ?? 'Product') }}</td><td>{{ number_format((float) $item->qty_returned, 4) }}</td><td>{{ number_format((float) $item->unit_price_amount, 2) }}</td><td>{{ number_format((float) $item->unit_cost_amount, 2) }}</td><td>{{ number_format((float) $item->line_total_amount, 2) }}</td></tr>@endforeach</tbody>
        </table>
    </div>
</x-tallui-card>
</div>

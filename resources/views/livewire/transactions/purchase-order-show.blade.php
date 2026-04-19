<div>
<x-tallui-notification />

<x-tallui-page-header
    :title="$record->po_number"
    subtitle="View purchase order details, supplier info, totals, and document actions."
    icon="o-arrow-down-tray"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Purchase Orders', 'href' => route('inventory.purchase-orders.index')],
            ['label' => $record->po_number],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-button label="Edit" icon="o-pencil-square" :link="route('inventory.purchase-orders.edit', ['recordId' => $record->getKey()])" class="btn-ghost btn-sm" />
        @if (Route::has('erp.documents.purchases.print'))
            <x-tallui-button label="Print" icon="o-printer" :link="route('erp.documents.purchases.print', ['purchaseOrder' => $record->getKey()])" class="btn-ghost btn-sm" />
        @endif
        @if (Route::has('erp.documents.purchases.pdf'))
            <x-tallui-button label="PDF" icon="o-arrow-down-tray" :link="route('erp.documents.purchases.pdf', ['purchaseOrder' => $record->getKey()])" class="btn-ghost btn-sm" />
        @endif
    </x-slot:actions>
</x-tallui-page-header>

<div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
    <div class="space-y-4 xl:col-span-1">
        <x-tallui-card title="Summary" subtitle="Supplier and purchase context." icon="o-document-text" :shadow="true">
            <div class="space-y-3 text-sm">
                <div><span class="text-base-content/50">Supplier</span><div class="font-medium">{{ $record->supplier?->name ?? '—' }}</div></div>
                <div><span class="text-base-content/50">Warehouse</span><div class="font-medium">{{ $record->warehouse?->name ?? '—' }}</div></div>
                <div><span class="text-base-content/50">Status</span><div class="font-medium">{{ $record->status?->label() ?? '—' }}</div></div>
                <div><span class="text-base-content/50">Expected At</span><div class="font-medium">{{ $record->expected_at?->format('M d, Y') ?? '—' }}</div></div>
                <div><span class="text-base-content/50">Ordered At</span><div class="font-medium">{{ $record->ordered_at?->format('M d, Y h:i A') ?? '—' }}</div></div>
                <div><span class="text-base-content/50">Notes</span><div class="font-medium whitespace-pre-line">{{ $record->notes ?: '—' }}</div></div>
            </div>
        </x-tallui-card>

        <x-tallui-card title="Finance" subtitle="Track dues and open accounting actions." icon="o-banknotes" :shadow="true">
            @if ($financeDocument)
                <div class="space-y-3 text-sm">
                    <div><span class="text-base-content/50">Bill</span><div class="font-medium">{{ $financeDocument['number'] }}</div></div>
                    <div><span class="text-base-content/50">Status</span><div class="font-medium">{{ $financeDocument['status'] }}</div></div>
                    <div><span class="text-base-content/50">Due Date</span><div class="font-medium">{{ $financeDocument['due_date'] }}</div></div>
                    <div><span class="text-base-content/50">Total</span><div class="font-medium">{{ number_format($financeDocument['total'], 2) }}</div></div>
                    <div><span class="text-base-content/50">Paid</span><div class="font-medium text-success">{{ number_format($financeDocument['paid'], 2) }}</div></div>
                    <div><span class="text-base-content/50">Due</span><div class="font-semibold {{ $financeDocument['is_due'] ? 'text-warning' : 'text-success' }}">{{ number_format($financeDocument['balance'], 2) }}</div></div>
                </div>
            @else
                <p class="text-sm text-base-content/60">No linked accounting bill yet. Once the accounting document is synced, you can check dues and record payment here.</p>
            @endif

            <div class="mt-4 flex flex-wrap gap-2">
                @if ($financeDocument && Route::has('accounting.bills'))
                    <x-tallui-button
                        label="View Bill"
                        icon="o-document-text"
                        :link="route('accounting.bills', ['search' => $financeDocument['number']])"
                        class="btn-ghost btn-sm"
                    />
                @endif
                @if ($financeDocument && $financeDocument['is_due'] && Route::has('accounting.bills'))
                    <x-tallui-button
                        label="Add Payment"
                        icon="o-banknotes"
                        :link="route('accounting.bills', ['search' => $financeDocument['number'], 'bill' => $financeDocument['id'], 'action' => 'pay'])"
                        class="btn-warning btn-sm"
                    />
                @endif
                @if (Route::has('accounting.journal'))
                    <x-tallui-button
                        label="Create Journal"
                        icon="o-pencil-square"
                        :link="route('accounting.journal', ['create' => 1, 'reference' => $record->po_number, 'description' => 'Purchase order ' . $record->po_number])"
                        class="btn-ghost btn-sm"
                    />
                @endif
            </div>
        </x-tallui-card>
    </div>

    <x-tallui-card title="Line Items" subtitle="Products, quantities, and cost lines." icon="o-queue-list" :shadow="true" class="xl:col-span-2">
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Qty</th>
                        <th>Unit Price</th>
                        <th>Line Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($record->items as $item)
                        <tr>
                            <td>{{ $item->product?->name ?? 'Product' }}</td>
                            <td>{{ $item->product?->sku ?? '—' }}</td>
                            <td>{{ rtrim(rtrim(number_format((float) $item->qty_ordered, 4, '.', ''), '0'), '.') }}</td>
                            <td>{{ number_format((float) $item->unit_price_local, 2) }}</td>
                            <td>{{ number_format((float) $item->line_total_local, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-5 ml-auto w-full max-w-sm space-y-2 text-sm">
            <div class="flex justify-between"><span class="text-base-content/60">Subtotal</span><strong>{{ number_format((float) $record->subtotal_local, 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Tax</span><strong>{{ number_format((float) $record->tax_local, 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Shipping</span><strong>{{ number_format((float) $record->shipping_local, 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Other Charges</span><strong>{{ number_format((float) $record->other_charges_amount, 2) }}</strong></div>
            <div class="flex justify-between text-base font-semibold"><span>Total</span><strong>{{ number_format((float) $record->total_local, 2) }}</strong></div>
        </div>
    </x-tallui-card>
</div>
</div>

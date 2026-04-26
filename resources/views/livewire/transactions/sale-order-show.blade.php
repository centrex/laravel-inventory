<div>
<x-tallui-notification />

<x-tallui-page-header
    :title="$record->so_number"
    :subtitle="'View ' . strtolower($documentLabel) . ' details, customer info, totals, and document actions.'"
    icon="o-shopping-cart"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => $documentLabel === 'Quotation' ? 'Quotations' : 'Sale Orders', 'href' => route($routeBase . '.index')],
            ['label' => $record->so_number],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        @if ($canConfirm)
            <x-tallui-button label="{{ $documentLabel === 'Quotation' ? 'Confirm Quote' : 'Confirm Order' }}" icon="o-check-circle" class="btn-primary btn-sm" wire:click="confirm" wire:confirm="Confirm this {{ strtolower($documentLabel) }}?" />
        @endif
        @if ($canCreateSaleOrder)
            <x-tallui-button label="Create Sale Order" icon="o-document-duplicate" class="btn-secondary btn-sm" wire:click="createSaleOrder" wire:confirm="Create a regular sale order from this quotation?" />
        @endif
        @if ($canReserve)
            <x-tallui-button label="Reserve Stock" icon="o-archive-box-arrow-down" class="btn-warning btn-sm" wire:click="reserve" wire:confirm="Reserve stock for this sale order?" />
        @endif
        @if ($canFulfill)
            <x-tallui-button label="Fulfill Remaining" icon="o-truck" class="btn-success btn-sm" wire:click="fulfill" wire:confirm="Fulfill all remaining quantities for this sale order?" />
        @endif
        @if ($canCancel)
            <x-tallui-button label="Cancel" icon="o-x-circle" class="btn-error btn-sm" wire:click="cancel" wire:confirm="Cancel this {{ strtolower($documentLabel) }}?" />
        @endif
        <x-tallui-button label="Edit" icon="o-pencil-square" :link="route($routeBase . '.edit', ['recordId' => $record->getKey()])" class="btn-ghost btn-sm" />
        @if (Route::has('erp.documents.sales.print'))
            <x-tallui-button label="Print" icon="o-printer" :link="route('erp.documents.sales.print', ['saleOrder' => $record->getKey()])" class="btn-ghost btn-sm" />
        @endif
        @if (Route::has('erp.documents.sales.pdf'))
            <x-tallui-button label="PDF" icon="o-arrow-down-tray" :link="route('erp.documents.sales.pdf', ['saleOrder' => $record->getKey()])" :no-wire-navigate="true" class="btn-ghost btn-sm" />
        @endif
    </x-slot:actions>
</x-tallui-page-header>

<div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
    <div class="space-y-4 xl:col-span-1">
        <x-tallui-card title="Summary" subtitle="Customer and order context." icon="o-document-text" :shadow="true">
            <div class="space-y-3 text-sm">
                <div><span class="text-base-content/50">Shop</span><div class="font-medium">{{ data_get($record->customer?->meta, 'company_name') ?: ($record->customer?->name ?? 'Walk-in') }}</div></div>
                <div><span class="text-base-content/50">Warehouse</span><div class="font-medium">{{ $record->warehouse?->name ?? '—' }}</div></div>
                <div><span class="text-base-content/50">Status</span><div class="font-medium">{{ $record->status?->label() ?? '—' }}</div></div>
                <div><span class="text-base-content/50">Price Tier</span><div class="font-medium">{{ $record->price_tier_name ?? '—' }}</div></div>
                <div><span class="text-base-content/50">Coupon</span><div class="font-medium">{{ $record->coupon_code ?: '—' }}</div></div>
                <div><span class="text-base-content/50">Ordered At</span><div class="font-medium">{{ $record->ordered_at?->format('M d, Y h:i A') ?? '—' }}</div></div>
                <div><span class="text-base-content/50">Notes</span><div class="font-medium whitespace-pre-line">{{ $record->notes ?: '—' }}</div></div>
            </div>
        </x-tallui-card>

        <x-tallui-card title="Finance" subtitle="Track dues and open accounting actions." icon="o-banknotes" :shadow="true">
            @if ($financeDocument)
                <div class="space-y-3 text-sm">
                    <div><span class="text-base-content/50">Invoice</span><div class="font-medium">{{ $financeDocument['number'] }}</div></div>
                    <div><span class="text-base-content/50">Status</span><div class="font-medium">{{ $financeDocument['status'] }}</div></div>
                    <div><span class="text-base-content/50">Due Date</span><div class="font-medium">{{ $financeDocument['due_date'] }}</div></div>
                    <div><span class="text-base-content/50">Total</span><div class="font-medium">{{ number_format($financeDocument['total'], 2) }}</div></div>
                    <div><span class="text-base-content/50">Paid</span><div class="font-medium text-success">{{ number_format($financeDocument['paid'], 2) }}</div></div>
                    <div><span class="text-base-content/50">Due</span><div class="font-semibold {{ $financeDocument['is_due'] ? 'text-warning' : 'text-success' }}">{{ number_format($financeDocument['balance'], 2) }}</div></div>
                </div>

                <div class="mt-4 border-t border-base-200 pt-4">
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-base-content/50">Payments</div>
                    <div class="space-y-3">
                        @forelse ($financeDocument['payments'] as $payment)
                            <div class="rounded-xl border border-base-200 bg-base-50/60 p-3 text-sm">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="font-medium">{{ $payment['date'] }}</div>
                                        <div class="text-base-content/60">{{ $payment['method'] }}</div>
                                        @if ($payment['reference'])
                                            <div class="text-xs text-base-content/50">Ref: {{ $payment['reference'] }}</div>
                                        @endif
                                        @if ($payment['notes'])
                                            <div class="mt-1 whitespace-pre-line text-xs text-base-content/60">{{ $payment['notes'] }}</div>
                                        @endif
                                    </div>
                                    <div class="text-right">
                                        <div class="font-semibold text-success">{{ number_format($payment['amount'], 2) }}</div>
                                        <div class="text-xs text-base-content/50">{{ $payment['journal_entry'] ?: 'Manual payment' }}</div>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-base-content/60">No payments recorded yet.</p>
                        @endforelse
                    </div>
                </div>
            @else
                <p class="text-sm text-base-content/60">No linked accounting invoice yet. Once the accounting document is synced, you can check dues and record payment here.</p>
            @endif

            <div class="mt-4 flex flex-wrap gap-2">
                @if ($canCreateInvoice)
                    <x-tallui-button
                        label="Create Invoice"
                        icon="o-plus-circle"
                        class="btn-primary btn-sm"
                        wire:click="createInvoice"
                        wire:confirm="Create an accounting invoice for this sale order?"
                    />
                @endif
                @if ($financeDocument && Route::has('accounting.invoices.show'))
                    <x-tallui-button
                        label="View Invoice"
                        icon="o-document-text"
                        :link="route('accounting.invoices.show', ['invoice' => $financeDocument['id']])"
                        class="btn-ghost btn-sm"
                    />
                @endif
                @if ($financeDocument && $financeDocument['status_raw'] === 'draft' && Route::has('accounting.invoices'))
                    <x-tallui-button
                        label="Post Invoice"
                        icon="o-check-badge"
                        :link="route('accounting.invoices', ['search' => $financeDocument['number'], 'invoice' => $financeDocument['id'], 'action' => 'post'])"
                        class="btn-info btn-sm"
                    />
                @endif
                @if ($financeDocument && $financeDocument['status_raw'] === 'draft' && $financeDocument['is_due'] && Route::has('accounting.invoices'))
                    <x-tallui-button
                        label="Post & Add Payment"
                        icon="o-banknotes"
                        :link="route('accounting.invoices', ['invoice' => $financeDocument['id'], 'action' => 'post-and-pay'])"
                        class="btn-success btn-sm"
                    />
                @elseif ($financeDocument && $financeDocument['is_due'] && Route::has('accounting.invoices'))
                    <x-tallui-button
                        label="Add Payment"
                        icon="o-banknotes"
                        :link="route('accounting.invoices', ['search' => $financeDocument['number'], 'invoice' => $financeDocument['id'], 'action' => 'pay'])"
                        class="btn-success btn-sm"
                    />
                @endif
                @if ($linkedSaleOrder)
                    <x-tallui-button
                        label="View Sale Order"
                        icon="o-arrow-top-right-on-square"
                        :link="route('inventory.sale-orders.show', ['recordId' => $linkedSaleOrder['id']])"
                        class="btn-ghost btn-sm"
                    />
                @endif
                @if (Route::has('accounting.journal'))
                    <x-tallui-button
                        label="Create Journal"
                        icon="o-pencil-square"
                        :link="route('accounting.journal', ['create' => 1, 'reference' => $record->so_number, 'description' => 'Sale order ' . $record->so_number])"
                        class="btn-ghost btn-sm"
                    />
                @endif
            </div>
        </x-tallui-card>
    </div>

        <x-tallui-card title="Line Items" subtitle="Products, quantities, and pricing." icon="o-queue-list" :shadow="true" class="xl:col-span-2">
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Qty</th>
                        <th>Unit Price</th>
                        <th>Discount %</th>
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
                            <td>{{ number_format((float) $item->discount_pct, 2) }}</td>
                            <td>{{ number_format((float) $item->line_total_local, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-5 ml-auto w-full max-w-sm space-y-2 text-sm">
            <div class="flex justify-between"><span class="text-base-content/60">Subtotal</span><strong>{{ number_format((float) $record->subtotal_local, 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Tax</span><strong>{{ number_format((float) $record->tax_local, 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Discount</span><strong>{{ number_format((float) $record->discount_local, 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Shipping</span><strong>{{ number_format((float) $record->shipping_local, 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Coupon Discount</span><strong>{{ number_format((float) $record->coupon_discount_local, 2) }}</strong></div>
            <div class="flex justify-between text-base font-semibold"><span>Total</span><strong>{{ number_format((float) $record->total_local, 2) }}</strong></div>
        </div>
    </x-tallui-card>
</div>
</div>

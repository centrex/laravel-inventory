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
        @can('inventory.sale-orders.manage')
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
        @if ($canEdit)
            <x-tallui-button label="Edit" icon="o-pencil-square" :link="route($routeBase . '.edit', ['recordId' => $record->getKey()])" class="btn-ghost btn-sm" />
        @endif
        @endcan
        @if (Route::has('erp.documents.sales.print'))
            <x-tallui-button label="Print" icon="o-printer" :link="route('erp.documents.sales.print', ['saleOrder' => $record->getKey()])" class="btn-ghost btn-sm" />
        @endif
        @if (Route::has('erp.documents.sales.pdf'))
            <x-tallui-button label="PDF" icon="o-arrow-down-tray" :link="route('erp.documents.sales.pdf', ['saleOrder' => $record->getKey()])" :no-wire-navigate="true" class="btn-ghost btn-sm" />
        @endif
    </x-slot:actions>
</x-tallui-page-header>

@if ($routeBase === 'inventory.sale-orders')
    <div class="mb-4">
        @if ($saleFlowHalted)
            <x-tallui-alert type="error" title="{{ $record->status?->label() }}">
                This sale order will not continue through the standard confirm &rarr; reserve &rarr; fulfill flow.
            </x-tallui-alert>
        @else
            <x-tallui-card :shadow="true" padding="normal">
                <x-tallui-steps :steps="$saleFlowSteps" :current="$saleFlowCurrent" />
            </x-tallui-card>
        @endif
    </div>
@endif

<div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
    <div class="space-y-4 xl:col-span-1">
        <x-tallui-card title="Summary" subtitle="Customer and order context." icon="o-document-text" :shadow="true">
            {{-- Customer block --}}
            <div class="space-y-1.5 text-sm">
                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-base-content/40">Customer</div>

                @if ($record->customer?->organization_name)
                    <div class="flex items-start justify-between gap-3">
                        <span class="shrink-0 text-base-content/50">Shop</span>
                        <a href="{{ route('inventory.entities.customers.edit', ['recordId' => $record->customer->getKey()]) }}" class="text-right font-medium text-primary hover:underline" wire:navigate>
                            {{ $record->customer->organization_name }}</span>
                        </a>
                    </div>
                @endif

                <div class="flex items-start justify-between gap-3">
                    <span class="shrink-0 text-base-content/50">Name</span>
                    @if ($record->customer)
                        <span class="text-right font-medium">{{ $record->customer->name }}</span>
                    @else
                        <span class="font-medium">Walk-in</span>
                    @endif
                </div>

                @if ($record->customer?->phone)
                    <div class="flex items-start justify-between gap-3">
                        <span class="shrink-0 text-base-content/50">Phone</span>
                        <span class="text-right font-medium">{{ $record->customer->phone }}</span>
                    </div>
                @endif

                @if ($record->customer?->email)
                    <div class="flex items-start justify-between gap-3">
                        <span class="shrink-0 text-base-content/50">Email</span>
                        <span class="text-right font-medium">{{ $record->customer->email }}</span>
                    </div>
                @endif

                @php $customerAddress = $record->customer?->addresses->first() @endphp
                @if ($customerAddress)
                    <div class="flex items-start justify-between gap-3">
                        <span class="shrink-0 text-base-content/50">Address</span>
                        <span class="text-right font-medium whitespace-pre-line">{{ implode(', ', array_filter([$customerAddress->street, $customerAddress->street_extra, $customerAddress->city, $customerAddress->state, $customerAddress->post_code])) }}</span>
                    </div>
                @endif

                @if ($record->customer?->zone || $record->customer?->area)
                    <div class="flex items-start justify-between gap-3">
                        <span class="shrink-0 text-base-content/50">Zone / Area</span>
                        <span class="text-right font-medium">
                            {{ implode(' / ', array_filter([(string) ($record->customer?->zone ?? ''), (string) ($record->customer?->area ?? '')])) ?: '—' }}
                        </span>
                    </div>
                @endif
            </div>

            <div class="my-3 border-t border-base-200"></div>

            {{-- Order block --}}
            <div class="space-y-1.5 text-sm">
                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-base-content/40">Order</div>

                <div class="flex items-center justify-between gap-3">
                    <span class="text-base-content/50">Warehouse</span>
                    <span class="font-medium">{{ $record->warehouse?->name ?? '—' }}</span>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-base-content/50">Status</span>
                    <span class="font-medium">{{ $record->status?->label() ?? '—' }}</span>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-base-content/50">Price Tier</span>
                    <span class="font-medium">{{ $record->price_tier_name ?? '—' }}</span>
                </div>
                @if ($record->coupon_code)
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-base-content/50">Coupon</span>
                        <span class="font-medium font-mono text-xs">{{ $record->coupon_code }}</span>
                    </div>
                @endif
                <div class="flex items-center justify-between gap-3">
                    <span class="text-base-content/50">Ordered At</span>
                    <span class="font-medium">{{ $record->ordered_at?->format('M d, Y h:i A') ?? '—' }}</span>
                </div>
            </div>

            @if ($record->notes)
                <div class="mt-3 border-t border-base-200 pt-3">
                    <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-base-content/40">Notes</div>
                    <p class="whitespace-pre-line text-sm text-base-content/70">{{ $record->notes }}</p>
                </div>
            @endif
        </x-tallui-card>

        <x-tallui-card title="Sales Team" subtitle="Creator and assigned sales personnel." icon="o-user-group" :shadow="true">
            <div class="space-y-3 text-sm">
                <div>
                    <span class="text-base-content/50">Created By</span>
                    <div class="font-medium">{{ $record->createdBy?->name ?? '—' }}</div>
                </div>
                <div>
                    <span class="text-base-content/50">Sales Manager</span>
                    <div class="font-medium">{{ $record->salesManager?->name ?? '—' }}</div>
                </div>
                @if ($record->salesAssistantManager)
                    <div>
                        <span class="text-base-content/50">Asst. Sales Manager</span>
                        <div class="font-medium">{{ $record->salesAssistantManager->name }}</div>
                    </div>
                @endif
                @if ($record->salesExecutive)
                    <div>
                        <span class="text-base-content/50">Sales Executive</span>
                        <div class="font-medium">{{ $record->salesExecutive->name }}</div>
                    </div>
                @endif
            </div>
        </x-tallui-card>

        @can('accounting.invoice.view')
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
        @endcan
    </div>

    <div class="xl:col-span-2">
        <x-tallui-card title="Line Items" subtitle="Products, quantities, and pricing." icon="o-queue-list" :shadow="true">
            {{-- Mobile: card stack (< sm) --}}
            <div class="space-y-3 sm:hidden">
                @foreach ($record->items as $item)
                    @php
                        $productLabel = $item->variant
                            ? trim(($item->product?->name ?? 'Product') . ' / ' . $item->variant->name)
                            : ($item->product?->name ?? 'Product');
                        $sku = $item->variant?->sku ?? $item->product?->sku ?? '—';
                        $qty = rtrim(rtrim(number_format((float) $item->qty_ordered, 4, '.', ''), '0'), '.');
                    @endphp
                    <div class="rounded-xl border border-base-200 bg-base-50/50 p-3 text-sm">
                        <div class="font-medium text-base-content">{{ $productLabel }}</div>
                        <div class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-base-content/60">
                            <div><span class="uppercase tracking-wide">SKU</span><div class="font-medium text-base-content/80">{{ $sku }}</div></div>
                            <div><span class="uppercase tracking-wide">Qty</span><div class="font-medium text-base-content/80">{{ $qty }}</div></div>
                            <div><span class="uppercase tracking-wide">Unit Price</span><div class="font-medium text-base-content/80">{{ number_format((float) $item->unit_price_local, 2) }}</div></div>
                            <div><span class="uppercase tracking-wide">Discount</span><div class="font-medium text-base-content/80">{{ number_format((float) $item->discount_pct, 2) }}%</div></div>
                        </div>
                        <div class="mt-2 flex items-center justify-between border-t border-base-200 pt-2">
                            <span class="text-xs text-base-content/50">Line Total</span>
                            <span class="font-semibold text-base-content">{{ number_format((float) $item->line_total_local, 2) }}</span>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Tablet+: table (>= sm) --}}
            <div class="hidden overflow-x-auto sm:block">
                <table class="table table-sm w-full">
                    <thead>
                        <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                            <th>Product</th>
                            <th>SKU</th>
                            <th class="text-right">Qty</th>
                            <th class="text-right">Unit Price</th>
                            <th class="text-right">Discount %</th>
                            <th class="text-right">Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($record->items as $item)
                            <tr class="even:bg-base-200/50 hover:bg-base-200">
                                <td>{{ $item->variant ? trim(($item->product?->name ?? 'Product') . ' / ' . $item->variant->name) : ($item->product?->name ?? 'Product') }}</td>
                                <td class="text-base-content/60">{{ $item->variant?->sku ?? $item->product?->sku ?? '—' }}</td>
                                <td class="text-right">{{ rtrim(rtrim(number_format((float) $item->qty_ordered, 4, '.', ''), '0'), '.') }}</td>
                                <td class="text-right">{{ number_format((float) $item->unit_price_local, 2) }}</td>
                                <td class="text-right">{{ number_format((float) $item->discount_pct, 2) }}</td>
                                <td class="text-right font-medium">{{ number_format((float) $item->line_total_local, 2) }}</td>
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
                <div class="flex justify-between border-t border-base-200 pt-2 text-base font-semibold"><span>Total</span><strong>{{ number_format((float) $record->total_local, 2) }}</strong></div>
            </div>
        </x-tallui-card>
    </div>
</div>

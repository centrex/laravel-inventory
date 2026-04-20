<div>
<x-tallui-page-header title="Inventory Reports" subtitle="Operational sales, purchase, and payment reporting aligned with the legacy Octa workflow." icon="o-chart-bar">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Reports'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-tallui-input type="date" wire:model.live="startDate" class="input-sm" />
            <x-tallui-input type="date" wire:model.live="endDate" class="input-sm" />
        </div>
    </x-slot:actions>
</x-tallui-page-header>

<div class="stats shadow w-full mb-6">
    <x-tallui-stat title="Sales Net" :value="number_format($salesMetrics['net_total'], 2)" :desc="'Discount ' . number_format($salesMetrics['discount'], 2)" icon="o-shopping-cart" />
    <x-tallui-stat title="Purchases Net" :value="number_format($purchaseMetrics['net_total'], 2)" :desc="'Shipping ' . number_format($purchaseMetrics['shipping'], 2)" icon="o-arrow-down-tray" />
    <x-tallui-stat title="Collections" :value="number_format($paymentMetrics['collections'], 2)" :desc="'Supplier pay ' . number_format($paymentMetrics['supplier_payments'], 2)" icon="o-banknotes" />
</div>

<div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
    <x-tallui-card title="Sales Report" subtitle="Octa-style sales totals, discount, tax, paid, and due." icon="o-shopping-cart" :shadow="true">
        <div class="space-y-2 text-sm">
            <div class="flex justify-between"><span class="text-base-content/60">Orders</span><strong>{{ $salesMetrics['count'] }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Gross Subtotal</span><strong>{{ number_format($salesMetrics['gross_subtotal'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Discount</span><strong>{{ number_format($salesMetrics['discount'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Tax</span><strong>{{ number_format($salesMetrics['tax'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Net Total</span><strong>{{ number_format($salesMetrics['net_total'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Fulfilled Value</span><strong>{{ number_format($salesMetrics['fulfilled_total'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Collected</span><strong class="text-success">{{ number_format($salesMetrics['invoice_paid'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Due</span><strong class="{{ $salesMetrics['invoice_due'] > 0 ? 'text-warning' : 'text-success' }}">{{ number_format($salesMetrics['invoice_due'], 2) }}</strong></div>
        </div>
        <div class="mt-4 flex flex-wrap gap-2 text-xs">
            @foreach ($salesMetrics['status_counts'] as $status => $count)
                <x-tallui-badge type="primary">{{ ucfirst(str_replace('_', ' ', $status)) }}: {{ $count }}</x-tallui-badge>
            @endforeach
        </div>
        <div class="mt-5 space-y-3 border-t border-base-200 pt-4 text-sm">
            @forelse ($saleOrders as $order)
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="font-medium">{{ $order->so_number }}</div>
                        <div class="text-xs text-base-content/60">{{ $order->customer?->name ?? 'Walk-in' }} · {{ $order->ordered_at?->format('M d, Y') ?? '—' }}</div>
                    </div>
                    <div class="text-right">
                        <div>{{ number_format((float) $order->total_local, 2) }}</div>
                        <div class="text-xs text-base-content/60">Disc {{ number_format((float) $order->discount_local, 2) }}</div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-base-content/60">No sale orders found for this period.</p>
            @endforelse
        </div>
    </x-tallui-card>

    <x-tallui-card title="Purchase Report" subtitle="Octa-style purchase totals, shipping, paid, and due." icon="o-arrow-down-tray" :shadow="true">
        <div class="space-y-2 text-sm">
            <div class="flex justify-between"><span class="text-base-content/60">Orders</span><strong>{{ $purchaseMetrics['count'] }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Gross Subtotal</span><strong>{{ number_format($purchaseMetrics['gross_subtotal'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Tax</span><strong>{{ number_format($purchaseMetrics['tax'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Shipping</span><strong>{{ number_format($purchaseMetrics['shipping'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Other Charges</span><strong>{{ number_format($purchaseMetrics['other_charges'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Net Total</span><strong>{{ number_format($purchaseMetrics['net_total'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Received Value</span><strong>{{ number_format($purchaseMetrics['received_total'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Paid</span><strong class="text-success">{{ number_format($purchaseMetrics['bill_paid'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Due</span><strong class="{{ $purchaseMetrics['bill_due'] > 0 ? 'text-warning' : 'text-success' }}">{{ number_format($purchaseMetrics['bill_due'], 2) }}</strong></div>
        </div>
        <div class="mt-4 flex flex-wrap gap-2 text-xs">
            @foreach ($purchaseMetrics['status_counts'] as $status => $count)
                <x-tallui-badge type="info">{{ ucfirst(str_replace('_', ' ', $status)) }}: {{ $count }}</x-tallui-badge>
            @endforeach
        </div>
        <div class="mt-5 space-y-3 border-t border-base-200 pt-4 text-sm">
            @forelse ($purchaseOrders as $order)
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="font-medium">{{ $order->po_number }}</div>
                        <div class="text-xs text-base-content/60">{{ $order->supplier?->name ?? '—' }} · {{ $order->ordered_at?->format('M d, Y') ?? '—' }}</div>
                    </div>
                    <div class="text-right">
                        <div>{{ number_format((float) $order->total_local, 2) }}</div>
                        <div class="text-xs text-base-content/60">Ship {{ number_format((float) $order->shipping_local, 2) }}</div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-base-content/60">No purchase orders found for this period.</p>
            @endforelse
        </div>
    </x-tallui-card>

    <x-tallui-card title="Payment Report" subtitle="Customer collections and supplier payments, separated like the old Octa cash view." icon="o-banknotes" :shadow="true">
        @if ($paymentMetrics['available'])
            <div class="space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-base-content/60">Payment Rows</span><strong>{{ $paymentMetrics['count'] }}</strong></div>
                <div class="flex justify-between"><span class="text-base-content/60">Customer Collections</span><strong class="text-success">{{ number_format($paymentMetrics['collections'], 2) }}</strong></div>
                <div class="flex justify-between"><span class="text-base-content/60">Supplier Payments</span><strong>{{ number_format($paymentMetrics['supplier_payments'], 2) }}</strong></div>
            </div>
            <div class="mt-5 space-y-3 border-t border-base-200 pt-4 text-sm">
                @forelse ($paymentMetrics['recent'] as $payment)
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <div class="font-medium">{{ $payment->payment_number }}</div>
                            <div class="text-xs text-base-content/60">
                                {{ class_basename((string) $payment->payable_type) }} · {{ $payment->payment_date?->format('M d, Y') ?? '—' }}
                            </div>
                        </div>
                        <div class="text-right">
                            <div>{{ number_format((float) $payment->amount, 2) }}</div>
                            <div class="text-xs text-base-content/60">{{ $payment->payment_method ?? '—' }}</div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-base-content/60">No payments found for this period.</p>
                @endforelse
            </div>
        @else
            <p class="text-sm text-base-content/60">Accounting payments are not available, so only inventory-side order totals can be shown.</p>
        @endif
    </x-tallui-card>
</div>
</div>

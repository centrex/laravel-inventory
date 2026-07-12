<div>
<x-tallui-page-header title="Sales Report" subtitle="Sales totals, discount, tax, collections, and product performance." icon="o-shopping-cart">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Reports', 'href' => route('inventory.reports.index')],
            ['label' => 'Sales'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <div class="flex flex-wrap items-center gap-2">
            <div class="w-56">
                <div class="flex items-start gap-1">
                    <div class="flex-1" wire:key="sales-customer-select-{{ $customerId ?? 'none' }}">
                        <x-tallui-select
                            name="customerId"
                            wire:model.live="customerId"
                            :value="$customerId"
                            searchable
                            placeholder="All customers"
                            :options="$selectedCustomerOptions"
                            :search-url="parse_url(route('inventory.async-select', ['resource' => 'customers']), PHP_URL_PATH)"
                            class="select-sm"
                        />
                    </div>
                    @if ($customerId)
                        <x-tallui-button type="button" icon="o-x-mark" class="btn-ghost btn-sm mt-0.5" wire:click="$set('customerId', null)" :tooltip="'Clear customer'" />
                    @endif
                </div>
                @if ($customerLedgerUrl)
                    <a href="{{ $customerLedgerUrl }}" class="mt-1 inline-flex items-center gap-1 text-xs text-primary hover:underline" wire:navigate>
                        <x-tallui-icon name="o-book-open" class="h-3.5 w-3.5" />
                        View customer ledger
                    </a>
                @endif
            </div>
            <x-tallui-input type="date" wire:model.live="startDate" class="input-sm" />
            <x-tallui-input type="date" wire:model.live="endDate" class="input-sm" />
        </div>
    </x-slot:actions>
</x-tallui-page-header>

<div class="stats shadow w-full mb-6">
    <x-tallui-stat title="Orders" :value="number_format($salesMetrics['count'])" icon="o-shopping-cart" />
    <x-tallui-stat title="Net Total" :value="number_format($salesMetrics['net_total'], 2)" :desc="'Discount ' . number_format($salesMetrics['discount'], 2)" icon="o-banknotes" />
    <x-tallui-stat title="Collected" :value="number_format($salesMetrics['invoice_paid'], 2)" icon="o-check-circle" icon-color="text-success" />
    <x-tallui-stat title="Due" :value="number_format($salesMetrics['invoice_due'], 2)" icon="o-exclamation-circle" :icon-color="$salesMetrics['invoice_due'] > 0 ? 'text-warning' : 'text-success'" />
    <x-tallui-stat title="Distinct Products" :value="number_format($productCount)" desc="Sold in this period" icon="o-cube" />
</div>

<div class="grid grid-cols-1 gap-4 xl:grid-cols-2 mb-6">
    <x-tallui-card title="Sales Totals" subtitle="Gross, discount, tax, shipping, and collection status." icon="o-shopping-cart" :shadow="true">
        <div class="space-y-2 text-sm">
            <div class="flex justify-between"><span class="text-base-content/60">Orders</span><strong>{{ $salesMetrics['count'] }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Gross Subtotal</span><strong>{{ number_format($salesMetrics['gross_subtotal'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Discount</span><strong>{{ number_format($salesMetrics['discount'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Tax</span><strong>{{ number_format($salesMetrics['tax'], 2) }}</strong></div>
            <div class="flex justify-between"><span class="text-base-content/60">Shipping</span><strong>{{ number_format($salesMetrics['shipping'], 2) }}</strong></div>
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
    </x-tallui-card>

    <x-tallui-card title="Recent Sale Orders" subtitle="Latest orders in the selected period." icon="o-clock" :shadow="true">
        <div class="space-y-3 text-sm">
            @forelse ($saleOrders as $order)
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="font-medium">{{ $order->so_number }}</div>
                        <div class="text-xs text-base-content/60">{{ $order->customer?->name ?? 'Walk-in' }} · {{ $order->ordered_at?->format('M d, Y') ?? '—' }}</div>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="text-right">
                            <div>{{ number_format((float) $order->total_local, 2) }}</div>
                            <div class="text-xs text-base-content/60">Disc {{ number_format((float) $order->discount_local, 2) }} · Ship {{ number_format((float) $order->shipping_local, 2) }}</div>
                        </div>
                        <x-tallui-button icon="o-eye" class="btn-ghost btn-xs" wire:click="viewOrder({{ $order->id }})" :tooltip="'View details'" />
                    </div>
                </div>
            @empty
                <p class="text-sm text-base-content/60">No sale orders found for this period.</p>
            @endforelse
        </div>
    </x-tallui-card>
</div>

<x-tallui-card title="Sold Products" subtitle="Units sold per product in the selected period, ranked by quantity. Draft and cancelled orders are excluded." icon="o-cube" :shadow="true" padding="none">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                    <th class="pl-5">Product</th>
                    <th class="text-right">Qty Sold</th>
                    <th class="text-right">Revenue</th>
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
                        <td class="pr-5 text-right text-sm text-base-content/60">{{ $row['orders_count'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">
                            <x-tallui-empty-state title="No products sold" description="No confirmed sale orders in this period yet." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-tallui-card>

<x-tallui-modal id="sale-order-detail" title="Sale Order Details" icon="o-shopping-cart" size="lg">
    @if ($viewingOrder)
        <div class="space-y-4">
            <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <div>
                    <div class="text-xs text-base-content/50">Order #</div>
                    <div class="font-medium">{{ $viewingOrder->so_number }}</div>
                </div>
                <div>
                    <div class="text-xs text-base-content/50">Date</div>
                    <div class="font-medium">{{ $viewingOrder->ordered_at?->format('M d, Y') ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-xs text-base-content/50">Customer</div>
                    <div class="font-medium">{{ $viewingOrder->customer?->name ?? 'Walk-in' }}</div>
                </div>
                <div>
                    <div class="text-xs text-base-content/50">Status</div>
                    <x-tallui-badge type="primary">{{ ucfirst(str_replace('_', ' ', $viewingOrder->status?->value ?? 'unknown')) }}</x-tallui-badge>
                </div>
                <div>
                    <div class="text-xs text-base-content/50">Warehouse</div>
                    <div class="font-medium">{{ $viewingOrder->warehouse?->name ?? '—' }}</div>
                </div>
            </div>

            <div class="overflow-x-auto rounded-xl border border-base-200">
                <table class="table table-sm w-full">
                    <thead>
                        <tr class="bg-base-200 text-xs text-base-content/60 uppercase tracking-wide">
                            <th class="pl-4">Product</th>
                            <th class="text-right">Qty</th>
                            <th class="text-right">Unit Price</th>
                            <th class="pr-4 text-right">Line Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-base-200">
                        @forelse ($viewingOrder->items as $item)
                            <tr>
                                <td class="pl-4">
                                    <div class="font-medium text-sm">{{ $item->variant?->display_name ?? $item->product?->name ?? '—' }}</div>
                                    <div class="text-xs text-base-content/50">{{ $item->variant?->sku ?: $item->product?->sku }}</div>
                                </td>
                                <td class="text-right font-mono text-sm">{{ number_format((float) $item->qty_ordered, 2) }}</td>
                                <td class="text-right font-mono text-sm">{{ number_format((float) $item->unit_price_local, 2) }}</td>
                                <td class="pr-4 text-right font-mono text-sm font-semibold">{{ number_format((float) $item->line_total_local, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-4 text-center text-sm text-base-content/60">No line items.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="ml-auto max-w-xs space-y-1.5 text-sm">
                <div class="flex justify-between"><span class="text-base-content/60">Subtotal</span><span>{{ number_format((float) $viewingOrder->subtotal_local, 2) }}</span></div>
                <div class="flex justify-between"><span class="text-base-content/60">Discount</span><span>{{ number_format((float) $viewingOrder->discount_local, 2) }}</span></div>
                <div class="flex justify-between"><span class="text-base-content/60">Tax</span><span>{{ number_format((float) $viewingOrder->tax_local, 2) }}</span></div>
                <div class="flex justify-between"><span class="text-base-content/60">Shipping</span><span>{{ number_format((float) $viewingOrder->shipping_local, 2) }}</span></div>
                <div class="flex justify-between border-t border-base-200 pt-1.5 font-semibold"><span>Total</span><span>{{ number_format((float) $viewingOrder->total_local, 2) }}</span></div>
            </div>
        </div>
    @else
        <div class="py-8 text-center text-sm text-base-content/60">Loading order…</div>
    @endif

    <x-slot:footer>
        <x-tallui-button wire:click="closeOrderModal" class="btn-ghost">Close</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>
</div>

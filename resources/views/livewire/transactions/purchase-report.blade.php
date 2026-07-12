<div>
<x-tallui-page-header title="Purchase Report" subtitle="Purchase totals, shipping, supplier payments, and product intake." icon="o-arrow-down-tray">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Reports', 'href' => route('inventory.reports.index')],
            ['label' => 'Purchases'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <div class="flex flex-wrap items-center gap-2">
            <div class="w-56">
                <div class="flex items-start gap-1">
                    <div class="flex-1" wire:key="purchase-supplier-select-{{ $supplierId ?? 'none' }}">
                        <x-tallui-select
                            name="supplierId"
                            wire:model.live="supplierId"
                            :value="$supplierId"
                            searchable
                            placeholder="All suppliers"
                            :options="$selectedSupplierOptions"
                            :search-url="parse_url(route('inventory.async-select', ['resource' => 'suppliers']), PHP_URL_PATH)"
                            class="select-sm"
                        />
                    </div>
                    @if ($supplierId)
                        <x-tallui-button type="button" icon="o-x-mark" class="btn-ghost btn-sm mt-0.5" wire:click="$set('supplierId', null)" :tooltip="'Clear supplier'" />
                    @endif
                </div>
                @if ($supplierLedgerUrl)
                    <a href="{{ $supplierLedgerUrl }}" class="mt-1 inline-flex items-center gap-1 text-xs text-primary hover:underline" wire:navigate>
                        <x-tallui-icon name="o-book-open" class="h-3.5 w-3.5" />
                        View supplier ledger
                    </a>
                @endif
            </div>
            <x-tallui-input type="date" wire:model.live="startDate" class="input-sm" />
            <x-tallui-input type="date" wire:model.live="endDate" class="input-sm" />
        </div>
    </x-slot:actions>
</x-tallui-page-header>

<div class="stats shadow w-full mb-6">
    <x-tallui-stat title="Orders" :value="number_format($purchaseMetrics['count'])" icon="o-arrow-down-tray" />
    <x-tallui-stat title="Net Total" :value="number_format($purchaseMetrics['net_total'], 2)" :desc="'Shipping ' . number_format($purchaseMetrics['shipping'], 2)" icon="o-banknotes" />
    <x-tallui-stat title="Paid" :value="number_format($purchaseMetrics['bill_paid'], 2)" icon="o-check-circle" icon-color="text-success" />
    <x-tallui-stat title="Due" :value="number_format($purchaseMetrics['bill_due'], 2)" icon="o-exclamation-circle" :icon-color="$purchaseMetrics['bill_due'] > 0 ? 'text-warning' : 'text-success'" />
    <x-tallui-stat title="Distinct Products" :value="number_format($productCount)" desc="Purchased in this period" icon="o-cube" />
</div>

<div class="grid grid-cols-1 gap-4 xl:grid-cols-2 mb-6">
    <x-tallui-card title="Purchase Totals" subtitle="Gross, tax, shipping, other charges, and payment status." icon="o-arrow-down-tray" :shadow="true">
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
    </x-tallui-card>

    <x-tallui-card title="Recent Purchase Orders" subtitle="Latest orders in the selected period." icon="o-clock" :shadow="true">
        <div class="space-y-3 text-sm">
            @forelse ($purchaseOrders as $order)
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="font-medium">{{ $order->po_number }}</div>
                        <div class="text-xs text-base-content/60">{{ $order->supplier?->name ?? '—' }} · {{ $order->ordered_at?->format('M d, Y') ?? '—' }}</div>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="text-right">
                            <div>{{ number_format((float) $order->total_local, 2) }}</div>
                            <div class="text-xs text-base-content/60">Ship {{ number_format((float) $order->shipping_local, 2) }}</div>
                        </div>
                        <x-tallui-button icon="o-eye" class="btn-ghost btn-xs" wire:click="viewOrder({{ $order->id }})" :tooltip="'View details'" />
                    </div>
                </div>
            @empty
                <p class="text-sm text-base-content/60">No purchase orders found for this period.</p>
            @endforelse
        </div>
    </x-tallui-card>
</div>

<x-tallui-card title="Purchased Products" subtitle="Units purchased per product in the selected period, ranked by quantity. Draft and cancelled orders are excluded." icon="o-cube" :shadow="true" padding="none">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                    <th class="pl-5">Product</th>
                    <th class="text-right">Qty Purchased</th>
                    <th class="text-right">Cost</th>
                    <th class="pr-5 text-right">Orders</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse ($purchasedProducts as $row)
                    <tr class="even:bg-base-200/50 hover:bg-base-200">
                        <td class="pl-5">
                            <div class="font-medium text-sm">{{ $row['name'] }}</div>
                            <div class="text-xs text-base-content/50">{{ $row['sku'] ?: '—' }}</div>
                        </td>
                        <td class="text-right font-mono text-sm font-semibold">{{ number_format($row['qty_purchased'], 2) }}</td>
                        <td class="text-right font-mono text-sm">{{ number_format($row['cost_local'], 2) }}</td>
                        <td class="pr-5 text-right text-sm text-base-content/60">{{ $row['orders_count'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">
                            <x-tallui-empty-state title="No products purchased" description="No confirmed purchase orders in this period yet." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-tallui-card>

<x-tallui-modal id="purchase-order-detail" title="Purchase Order Details" icon="o-arrow-down-tray" size="lg">
    @if ($viewingOrder)
        <div class="space-y-4">
            <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <div>
                    <div class="text-xs text-base-content/50">Order #</div>
                    <div class="font-medium">{{ $viewingOrder->po_number }}</div>
                </div>
                <div>
                    <div class="text-xs text-base-content/50">Date</div>
                    <div class="font-medium">{{ $viewingOrder->ordered_at?->format('M d, Y') ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-xs text-base-content/50">Supplier</div>
                    <div class="font-medium">{{ $viewingOrder->supplier?->name ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-xs text-base-content/50">Status</div>
                    <x-tallui-badge type="info">{{ ucfirst(str_replace('_', ' ', $viewingOrder->status?->value ?? 'unknown')) }}</x-tallui-badge>
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
                            <th class="text-right">Qty Ordered</th>
                            <th class="text-right">Qty Received</th>
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
                                <td class="text-right font-mono text-sm">{{ number_format((float) $item->qty_received, 2) }}</td>
                                <td class="text-right font-mono text-sm">{{ number_format((float) $item->unit_price_local, 2) }}</td>
                                <td class="pr-4 text-right font-mono text-sm font-semibold">{{ number_format((float) $item->line_total_local, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-4 text-center text-sm text-base-content/60">No line items.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="ml-auto max-w-xs space-y-1.5 text-sm">
                <div class="flex justify-between"><span class="text-base-content/60">Subtotal</span><span>{{ number_format((float) $viewingOrder->subtotal_local, 2) }}</span></div>
                <div class="flex justify-between"><span class="text-base-content/60">Tax</span><span>{{ number_format((float) $viewingOrder->tax_local, 2) }}</span></div>
                <div class="flex justify-between"><span class="text-base-content/60">Shipping</span><span>{{ number_format((float) $viewingOrder->shipping_local, 2) }}</span></div>
                <div class="flex justify-between"><span class="text-base-content/60">Other Charges</span><span>{{ number_format((float) $viewingOrder->other_charges_amount, 2) }}</span></div>
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

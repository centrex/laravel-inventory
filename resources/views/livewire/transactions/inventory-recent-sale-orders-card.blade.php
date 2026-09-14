<div>
<x-tallui-card title="Recent Sale Orders" subtitle="Orders in the selected period, newest first." icon="o-clock" :shadow="true" padding="none">
    <div class="flex items-center justify-between gap-3 px-5 pt-4 pb-2">
        <span class="text-xs text-base-content/60">
            @if ($saleOrders->total() > 0)
                Showing {{ $saleOrders->firstItem() }}–{{ $saleOrders->lastItem() }} of {{ number_format($saleOrders->total()) }}
            @else
                No orders found for this period.
            @endif
        </span>
        <select wire:model.live="perPage" class="select select-bordered select-xs w-auto">
            @foreach ([10, 15, 25, 50] as $option)
                <option value="{{ $option }}">{{ $option }} / page</option>
            @endforeach
        </select>
    </div>

    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                    <th class="pl-5">Order</th>
                    <th>Customer</th>
                    <th class="hidden sm:table-cell">Warehouse</th>
                    <th>Status</th>
                    <th class="text-right">Total</th>
                    <th class="text-right hidden sm:table-cell">Due</th>
                    <th class="pr-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse ($saleOrders as $order)
                    <tr class="even:bg-base-200/50 hover:bg-base-200" wire:key="recent-sale-order-{{ $order->id }}">
                        <td class="pl-5">
                            <div class="font-medium text-sm">{{ $order->so_number }}</div>
                            <div class="text-xs text-base-content/50">{{ $order->ordered_at?->format('M d, Y') ?? '—' }}</div>
                        </td>
                        <td class="text-sm">{{ $order->customer?->organization_name ?: ($order->customer?->name ?? 'Walk-in') }}</td>
                        <td class="text-sm text-base-content/60 hidden sm:table-cell">{{ $order->warehouse?->name ?? '—' }}</td>
                        <td>
                            <x-tallui-badge :type="\Centrex\Inventory\Support\StatusBadge::type($order->status)">
                                {{ $order->status?->label() ?? $order->status }}
                            </x-tallui-badge>
                        </td>
                        <td class="text-right font-mono text-sm font-semibold">{{ number_format((float) $order->total_local, 2) }}</td>
                        <td class="text-right font-mono text-sm hidden sm:table-cell">{{ number_format((float) $order->due_amount, 2) }}</td>
                        <td class="pr-5 text-right">
                            <x-tallui-button icon="o-eye" class="btn-ghost btn-xs" wire:click="viewOrder({{ $order->id }})" :tooltip="'View details'" />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <x-tallui-empty-state title="No recent sale orders" description="No sale orders found for this period." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($saleOrders->hasPages())
        <div class="border-t border-base-200 px-5 py-3">
            <x-tallui-pagination :paginator="$saleOrders" size="sm" align="end" :show-info="false" />
        </div>
    @endif
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

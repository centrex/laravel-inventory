<div>
<x-tallui-notification />

<x-tallui-page-header :title="$record->shipment_number" subtitle="Shipment route, receiving state, and contents." icon="o-paper-airplane">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Shipments', 'href' => route('inventory.shipments.index')],
            ['label' => $record->shipment_number],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-button label="Excel" icon="o-arrow-down-tray" wire:click="downloadExcel" class="btn-outline btn-sm" />
        @if ($record->status?->value === 'draft' && $canDispatch)
            <x-tallui-button label="Dispatch" icon="o-paper-airplane" class="btn-primary btn-sm" wire:click="dispatch_shipment" wire:confirm="Dispatch this shipment?" />
        @endif
        @if (in_array($record->status?->value, ['in_transit', 'partial'], true) && $canReceive)
            <x-tallui-button label="Receive Partial" icon="o-inbox-arrow-down" class="btn-ghost btn-sm" wire:click="openReceiveModal" />
            <x-tallui-button label="Receive All" icon="o-inbox-arrow-down" class="btn-success btn-sm" wire:click="receiveAll" wire:confirm="Mark all remaining items as received?" />
        @endif
    </x-slot:actions>
</x-tallui-page-header>

<div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
    <x-tallui-card title="Shipment Summary" subtitle="Route, status, and shipping cost." icon="o-map" :shadow="true">
        <div class="space-y-3 text-sm">
            <div><span class="text-base-content/50">From</span><div class="font-medium">{{ $record->fromWarehouse?->name ?? '—' }}</div></div>
            <div><span class="text-base-content/50">To</span><div class="font-medium">{{ $record->toWarehouse?->name ?? '—' }}</div></div>
            <div><span class="text-base-content/50">Status</span><div class="font-medium">{{ $record->status?->label() ?? ucfirst((string) $record->status) }}</div></div>
            <div><span class="text-base-content/50">Weight</span><div class="font-medium">{{ number_format((float) $record->total_weight_kg, 2) }} kg</div></div>
            <div><span class="text-base-content/50">Shipping Cost</span><div class="font-medium">{{ number_format((float) $record->shipping_cost_amount, 2) }}</div></div>
            <div><span class="text-base-content/50">Shipped</span><div class="font-medium">{{ $record->shipped_at?->format('M d, Y H:i') ?? '—' }}</div></div>
            <div><span class="text-base-content/50">Received</span><div class="font-medium">{{ $record->received_at?->format('M d, Y H:i') ?? '—' }}</div></div>
            <div><span class="text-base-content/50">Notes</span><div class="font-medium whitespace-pre-line">{{ $record->notes ?: '—' }}</div></div>
        </div>
    </x-tallui-card>

    <div class="space-y-4 xl:col-span-2">
    <x-tallui-card title="Shipment Lines" subtitle="Products, quantities, and landed costs." icon="o-queue-list" :shadow="true">
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                        <th>Product</th>
                        <th class="text-right">Qty Sent</th>
                        <th class="text-right">Qty Received</th>
                        <th class="text-right">Remaining</th>
                        <th class="text-right">Source Cost</th>
                        <th class="text-right">Landed Cost</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($record->items as $item)
                        @php $remaining = max(0.0, (float) $item->qty_sent - (float) $item->qty_received); @endphp
                        <tr>
                            <td>{{ $item->product?->name ?? '—' }}</td>
                            <td class="text-right font-mono">{{ rtrim(rtrim(number_format((float) $item->qty_sent, 4, '.', ''), '0'), '.') }}</td>
                            <td class="text-right font-mono">{{ rtrim(rtrim(number_format((float) $item->qty_received, 4, '.', ''), '0'), '.') }}</td>
                            <td class="text-right font-mono {{ $remaining > 0 ? 'text-warning' : 'text-success' }}">
                                {{ $remaining > 0 ? rtrim(rtrim(number_format($remaining, 4, '.', ''), '0'), '.') : '✓' }}
                            </td>
                            <td class="text-right font-mono">{{ number_format((float) $item->unit_cost_source_amount, 2) }}</td>
                            <td class="text-right font-mono">{{ number_format((float) $item->unit_landed_cost_amount, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-6 text-center text-sm text-base-content/60">No shipment lines recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-tallui-card>
    </div>
</div>

<x-tallui-card title="Box Details" subtitle="Shipment boxes and the products packed inside each box." icon="o-archive-box" :shadow="true" class="mt-4">
    <div class="space-y-4">
        @forelse ($record->boxes as $box)
            <div class="rounded-xl border border-base-200 bg-base-100">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-base-200 p-4">
                    <div>
                        <div class="font-semibold">{{ $box->box_code ?: 'Box #' . $box->getKey() }}</div>
                        <div class="text-xs text-base-content/60">
                            {{ number_format((float) $box->measured_weight_kg, 2) }} kg
                            @if ($box->notes) · {{ $box->notes }} @endif
                        </div>
                    </div>
                    <x-tallui-badge type="info">{{ $box->items->count() }} items</x-tallui-badge>
                </div>
                <div class="overflow-x-auto">
                    <table class="table table-sm w-full">
                        <thead>
                            <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                                <th class="pl-4">Product</th>
                                <th>Variant</th>
                                <th class="text-right">Qty Sent</th>
                                <th class="text-right">Alloc. Weight</th>
                                <th class="text-right">Source Cost</th>
                                <th class="text-right">Shipping</th>
                                <th class="pr-4 text-right">Landed Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($box->items as $item)
                                <tr class="even:bg-base-200/50 hover:bg-base-200">
                                    <td class="pl-4">{{ $item->product?->name ?? '—' }}</td>
                                    <td>{{ $item->variant?->name ?? $item->variant?->sku ?? '—' }}</td>
                                    <td class="text-right font-mono">{{ number_format((float) $item->qty_sent, 4) }}</td>
                                    <td class="text-right font-mono">{{ number_format((float) $item->allocated_weight_kg, 2) }} kg</td>
                                    <td class="text-right font-mono">{{ number_format((float) $item->source_unit_cost_amount, 2) }}</td>
                                    <td class="text-right font-mono">{{ number_format((float) $item->shipping_allocated_amount, 2) }}</td>
                                    <td class="pr-4 text-right font-mono">{{ number_format((float) $item->unit_landed_cost_amount, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="py-4 text-center text-sm text-base-content/60">No items recorded for this box.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <x-tallui-empty-state title="No boxes recorded" description="Box details will appear here when shipment boxes are available." icon="o-archive-box" size="sm" />
        @endforelse
    </div>
</x-tallui-card>

@if ($showReceiveModal)
    <div class="modal modal-open">
        <div class="modal-box max-w-2xl">
            <h3 class="text-lg font-bold mb-4">Receive Shipment Items</h3>
            <div class="overflow-x-auto">
                <table class="table table-sm w-full">
                    <thead>
                        <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                            <th>Product</th>
                            <th class="text-right w-24">Sent</th>
                            <th class="text-right w-24">Received</th>
                            <th class="text-right w-24">Remaining</th>
                            <th class="w-32">This Delivery</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($record->items as $item)
                            @php $remaining = max(0.0, (float) $item->qty_sent - (float) $item->qty_received); @endphp
                            <tr>
                                <td class="font-medium">{{ $item->product?->name ?? '—' }}</td>
                                <td class="text-right font-mono text-sm">{{ rtrim(rtrim(number_format((float) $item->qty_sent, 4, '.', ''), '0'), '.') }}</td>
                                <td class="text-right font-mono text-sm">{{ rtrim(rtrim(number_format((float) $item->qty_received, 4, '.', ''), '0'), '.') }}</td>
                                <td class="text-right font-mono text-sm {{ $remaining > 0 ? 'text-warning' : 'text-success' }}">
                                    {{ rtrim(rtrim(number_format($remaining, 4, '.', ''), '0'), '.') }}
                                </td>
                                <td>
                                    <input
                                        type="number"
                                        step="0.0001"
                                        min="0"
                                        max="{{ $remaining }}"
                                        wire:model="receiveQtys.{{ $item->id }}"
                                        class="input input-sm input-bordered w-28 text-right"
                                        @disabled($remaining <= 0)
                                    />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="modal-action">
                <x-tallui-button label="Cancel" class="btn-ghost" wire:click="$set('showReceiveModal', false)" />
                <x-tallui-button label="Confirm Receipt" icon="o-check" class="btn-success" wire:click="receivePartial" :spinner="'receivePartial'" />
            </div>
        </div>
        <div class="modal-backdrop" wire:click="$set('showReceiveModal', false)"></div>
    </div>
@endif

</div>

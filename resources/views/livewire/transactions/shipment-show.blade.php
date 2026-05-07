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
        <x-tallui-button label="Excel" icon="o-arrow-down-tray" wire:click="downloadExcel" class="btn-primary btn-sm" />
        <x-tallui-button label="Transfers" icon="o-arrows-right-left" :link="route('inventory.transfers.index')" class="btn-outline btn-sm" />
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

    <x-tallui-card title="Shipment Lines" subtitle="Products, quantities, and landed costs." icon="o-queue-list" :shadow="true" class="xl:col-span-2">
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                        <th>Product</th>
                        <th>Qty Sent</th>
                        <th>Qty Received</th>
                        <th>Weight</th>
                        <th>Source Cost</th>
                        <th>Landed Cost</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($record->items as $item)
                        <tr>
                            <td>{{ $item->product?->name ?? 'Product' }}</td>
                            <td>{{ number_format((float) $item->qty_sent, 4) }}</td>
                            <td>{{ number_format((float) $item->qty_received, 4) }}</td>
                            <td>{{ number_format((float) $item->weight_kg_total, 2) }} kg</td>
                            <td>{{ number_format((float) $item->unit_cost_source_amount, 2) }}</td>
                            <td>{{ number_format((float) $item->unit_landed_cost_amount, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-6 text-center text-sm text-base-content/60">No shipment lines recorded.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-tallui-card>
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
                            @if ($box->notes)
                                · {{ $box->notes }}
                            @endif
                        </div>
                    </div>
                    <x-tallui-badge type="info">{{ $box->items->count() }} items</x-tallui-badge>
                </div>
                <div class="overflow-x-auto">
                    <table class="table table-sm w-full">
                        <thead>
                            <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                                <th class="pl-4">Product</th>
                                <th>Variant</th>
                                <th>Qty Sent</th>
                                <th>Allocated Weight</th>
                                <th>Source Cost</th>
                                <th>Shipping</th>
                                <th class="pr-4">Landed Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($box->items as $item)
                                <tr>
                                    <td class="pl-4">{{ $item->product?->name ?? 'Product' }}</td>
                                    <td>{{ $item->variant?->name ?? $item->variant?->sku ?? '—' }}</td>
                                    <td>{{ number_format((float) $item->qty_sent, 4) }}</td>
                                    <td>{{ number_format((float) $item->allocated_weight_kg, 2) }} kg</td>
                                    <td>{{ number_format((float) $item->source_unit_cost_amount, 2) }}</td>
                                    <td>{{ number_format((float) $item->shipping_allocated_amount, 2) }}</td>
                                    <td class="pr-4">{{ number_format((float) $item->unit_landed_cost_amount, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-4 text-center text-sm text-base-content/60">No items recorded for this box.</td>
                                </tr>
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
</div>

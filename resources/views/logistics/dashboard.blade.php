<x-layouts::app>
<x-tallui-notification />

<x-tallui-page-header
    title="Logistics"
    subtitle="Dispatch, receiving, stock risk, and fulfillment control."
    icon="o-truck"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Logistics'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-button label="Transfers" icon="o-arrows-right-left" :link="route('inventory.transfers.index')" class="btn-outline btn-sm" />
        <x-tallui-button label="Shipments" icon="o-paper-airplane" :link="route('inventory.shipments.index')" class="btn-outline btn-sm" />
        <x-tallui-button label="Purchases" icon="o-arrow-down-tray" :link="route('inventory.purchase-orders.index')" class="btn-outline btn-sm" />
        <x-tallui-button label="Sales" icon="o-shopping-cart" :link="route('inventory.sale-orders.index')" class="btn-outline btn-sm" />
        <x-tallui-button label="Reports" icon="o-chart-bar" :link="route('inventory.reports.index')" class="btn-primary btn-sm" />
    </x-slot:actions>
</x-tallui-page-header>

<div class="stats shadow w-full mb-6">
    <x-tallui-stat title="Open Transfers" :value="$metrics['open_transfers']" desc="Draft, in transit, or partial transfers" icon="o-arrows-right-left" />
    <x-tallui-stat title="Open Shipments" :value="$metrics['open_shipments']" desc="Draft, in transit, or partial shipments" icon="o-paper-airplane" />
    <x-tallui-stat title="Pending Receipts" :value="$metrics['pending_receipts']" desc="Draft stock receipts" icon="o-inbox-arrow-down" />
    <x-tallui-stat title="Open Purchases" :value="$metrics['open_purchases']" desc="Submitted, confirmed, or partial POs" icon="o-arrow-down-tray" />
</div>

<div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
    <x-tallui-card title="Fulfillment Queue" subtitle="Sales waiting for reservation, processing, or partial shipment." icon="o-shopping-cart" :shadow="true">
        <div class="space-y-3 text-sm">
            @forelse ($fulfillmentQueue as $order)
                <div class="flex items-center justify-between gap-3 rounded-xl border border-base-200 bg-base-100 p-3">
                    <div>
                        <div class="font-medium">{{ $order->so_number }}</div>
                        <div class="text-xs text-base-content/60">{{ $order->customer?->name ?? 'Walk-in' }} · {{ $order->warehouse?->name ?? '—' }}</div>
                    </div>
                    <x-tallui-badge type="warning">{{ $order->status?->label() ?? $order->status }}</x-tallui-badge>
                </div>
            @empty
                <p class="text-sm text-base-content/60">No sales are waiting on logistics.</p>
            @endforelse
        </div>
    </x-tallui-card>

    <x-tallui-card title="Inbound Purchases" subtitle="Purchase orders waiting for supplier movement or receiving." icon="o-arrow-down-tray" :shadow="true">
        <div class="space-y-3 text-sm">
            @forelse ($openPurchases as $order)
                <div class="flex items-center justify-between gap-3 rounded-xl border border-base-200 bg-base-100 p-3">
                    <div>
                        <div class="font-medium">{{ $order->po_number }}</div>
                        <div class="text-xs text-base-content/60">{{ $order->supplier?->name ?? '—' }} · {{ $order->expected_at?->format('M d, Y') ?? 'No ETA' }}</div>
                    </div>
                    <x-tallui-badge type="info">{{ $order->status?->label() ?? $order->status }}</x-tallui-badge>
                </div>
            @empty
                <p class="text-sm text-base-content/60">No open purchase orders.</p>
            @endforelse
        </div>
    </x-tallui-card>

    <x-tallui-card title="Transfers" subtitle="Recent inter-warehouse stock movement." icon="o-arrows-right-left" :shadow="true">
        <div class="space-y-3 text-sm">
            @forelse ($transfers as $transfer)
                <div class="flex items-center justify-between gap-3 rounded-xl border border-base-200 bg-base-100 p-3">
                    <div>
                        <div class="font-medium">{{ $transfer->transfer_number }}</div>
                        <div class="text-xs text-base-content/60">{{ $transfer->fromWarehouse?->name ?? '—' }} to {{ $transfer->toWarehouse?->name ?? '—' }}</div>
                    </div>
                    <x-tallui-badge type="primary">{{ $transfer->status?->label() ?? $transfer->status }}</x-tallui-badge>
                </div>
            @empty
                <p class="text-sm text-base-content/60">No recent transfers.</p>
            @endforelse
        </div>
    </x-tallui-card>

    <x-tallui-card title="Shipments" subtitle="Recent shipment records tracked outside transfer workflows." icon="o-paper-airplane" :shadow="true">
        <div class="space-y-3 text-sm">
            @forelse ($shipments as $shipment)
                <div class="flex items-center justify-between gap-3 rounded-xl border border-base-200 bg-base-100 p-3">
                    <div>
                        <div class="font-medium">{{ $shipment->shipment_number }}</div>
                        <div class="text-xs text-base-content/60">{{ $shipment->fromWarehouse?->name ?? '—' }} to {{ $shipment->toWarehouse?->name ?? '—' }}</div>
                    </div>
                    <x-tallui-badge type="info">{{ $shipment->status?->label() ?? $shipment->status }}</x-tallui-badge>
                </div>
            @empty
                <p class="text-sm text-base-content/60">No recent shipments.</p>
            @endforelse
        </div>
    </x-tallui-card>

    <x-tallui-card title="Low Stock" subtitle="Items at or below reorder point." icon="o-exclamation-triangle" :shadow="true">
        <div class="space-y-3 text-sm">
            @forelse ($lowStock as $stock)
                <div class="flex items-center justify-between gap-3 rounded-xl border border-base-200 bg-base-100 p-3">
                    <div>
                        <div class="font-medium">{{ $stock->product?->name ?? 'Product #' . $stock->product_id }}</div>
                        <div class="text-xs text-base-content/60">{{ $stock->warehouse?->name ?? '—' }} · reorder {{ number_format((float) $stock->reorder_point, 2) }}</div>
                    </div>
                    <div class="text-right">
                        <div class="font-semibold">{{ number_format((float) $stock->qty_on_hand - (float) $stock->qty_reserved, 2) }}</div>
                        <div class="text-xs text-base-content/60">available</div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-base-content/60">No low-stock items.</p>
            @endforelse
        </div>
    </x-tallui-card>
</div>
</x-layouts::app>

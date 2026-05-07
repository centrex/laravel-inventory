<div>
<x-tallui-notification />

<x-tallui-page-header :title="$record->transfer_number" subtitle="Dispatch control, receive confirmation, and box contents." icon="o-truck">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Transfers', 'href' => route('inventory.transfers.index')],
            ['label' => $record->transfer_number],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        @if (($record->status?->value ?? (string) $record->status) === 'draft' && $canDispatch)
            <x-tallui-button label="Dispatch" icon="o-paper-airplane" class="btn-primary btn-sm" wire:click="dispatchTransfer" wire:confirm="Dispatch this transfer?" />
        @endif
        @if (in_array($record->status?->value ?? (string) $record->status, ['in_transit', 'partial'], true) && $canReceive)
            <x-tallui-button label="Receive" icon="o-inbox-arrow-down" class="btn-success btn-sm" wire:click="receive" wire:confirm="Mark this transfer as received?" />
        @endif
    </x-slot:actions>
</x-tallui-page-header>

<div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
    <x-tallui-card title="Transfer Summary" subtitle="Route, status, and shipping cost." icon="o-map" :shadow="true">
        <div class="space-y-3 text-sm">
            <div><span class="text-base-content/50">From</span><div class="font-medium">{{ $record->fromWarehouse?->name ?? '—' }}</div></div>
            <div><span class="text-base-content/50">To</span><div class="font-medium">{{ $record->toWarehouse?->name ?? '—' }}</div></div>
            <div><span class="text-base-content/50">Status</span><div class="font-medium">{{ $record->status?->label() ?? ucfirst((string) $record->status) }}</div></div>
            <div><span class="text-base-content/50">Weight</span><div class="font-medium">{{ number_format((float) $record->total_weight_kg, 2) }} kg</div></div>
            <div><span class="text-base-content/50">Shipping Cost</span><div class="font-medium">{{ number_format((float) $record->shipping_cost_amount, 2) }}</div></div>
            <div><span class="text-base-content/50">Notes</span><div class="font-medium whitespace-pre-line">{{ $record->notes ?: '—' }}</div></div>
        </div>
    </x-tallui-card>

    <x-tallui-card title="Transfer Lines" subtitle="Products and landed costs." icon="o-queue-list" :shadow="true" class="xl:col-span-2">
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                        <th>Product</th>
                        <th>Qty Sent</th>
                        <th>Qty Received</th>
                        <th>Source Cost</th>
                        <th>Landed Cost</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($record->items as $item)
                        <tr>
                            <td>{{ $item->product?->name ?? 'Product' }}</td>
                            <td>{{ number_format((float) $item->qty_sent, 4) }}</td>
                            <td>{{ number_format((float) $item->qty_received, 4) }}</td>
                            <td>{{ number_format((float) $item->unit_cost_source_amount, 2) }}</td>
                            <td>{{ number_format((float) $item->unit_landed_cost_amount, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-tallui-card>
</div>
</div>

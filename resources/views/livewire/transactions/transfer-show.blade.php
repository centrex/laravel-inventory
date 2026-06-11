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
        @if ($record->status?->value === 'pending' && $canDispatch)
            <x-tallui-button label="Dispatch" icon="o-paper-airplane" class="btn-primary btn-sm" wire:click="dispatchTransfer" wire:confirm="Dispatch this transfer?" />
        @endif
        @if (in_array($record->status?->value, ['dispatched', 'partial'], true) && $canReceive)
            <x-tallui-button label="Receive Partial" icon="o-inbox-arrow-down" class="btn-ghost btn-sm" wire:click="openReceiveModal" />
            <x-tallui-button label="Receive All" icon="o-inbox-arrow-down" class="btn-success btn-sm" wire:click="receiveAll" wire:confirm="Mark all remaining items as received?" />
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

    <div class="space-y-4 xl:col-span-2">
    <x-tallui-card title="Transfer Lines" subtitle="Products and landed costs." icon="o-queue-list" :shadow="true">
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                        <th>Product</th>
                        <th class="text-right">Qty Sent</th>
                        <th class="text-right">Qty Received</th>
                        <th class="text-right">Remaining</th>
                        <th class="text-right">Source Cost</th>
                        <th class="text-right">Landed Cost</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($record->items as $item)
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
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-tallui-card>
    </div>
</div>

{{-- Receive Partial Modal --}}
@if ($showReceiveModal)
    <div class="modal modal-open">
        <div class="modal-box max-w-2xl">
            <h3 class="text-lg font-bold mb-4">Receive Items</h3>

            <div class="overflow-x-auto">
                <table class="table table-sm w-full">
                    <thead>
                        <tr class="text-xs text-base-content/50 uppercase">
                            <th>Product</th>
                            <th class="text-right w-24">Sent</th>
                            <th class="text-right w-24">Already Received</th>
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

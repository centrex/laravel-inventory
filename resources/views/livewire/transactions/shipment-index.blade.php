<div>
<x-tallui-notification />

<x-tallui-page-header title="Shipments" subtitle="Track shipment movement separately from stock transfers." icon="o-paper-airplane">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Shipments'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-tallui-button label="Transfers" icon="o-arrows-right-left" :link="route('inventory.transfers.index')" class="btn-outline btn-sm" />
            <x-tallui-button label="Excel" icon="o-arrow-down-tray" wire:click="downloadExcel" class="btn-outline btn-sm" />
            <div class="w-60">
                <x-tallui-input placeholder="Search shipments…" wire:model.live.debounce.300ms="search" class="input-sm" />
            </div>
            <div class="w-44">
                <x-tallui-select wire:model.live="status" class="select-sm">
                    <option value="">All statuses</option>
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-tallui-select>
            </div>
        </div>
    </x-slot:actions>
</x-tallui-page-header>

<x-tallui-card padding="none" :shadow="true">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                    <th class="pl-5">Number</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Status</th>
                    <th>Boxes</th>
                    <th>Weight</th>
                    <th>Shipping Cost</th>
                    <th class="pr-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse ($shipments as $shipment)
                    <tr>
                        <td class="pl-5 font-mono font-semibold">{{ $shipment->shipment_number }}</td>
                        <td>{{ $shipment->fromWarehouse?->name ?? '—' }}</td>
                        <td>{{ $shipment->toWarehouse?->name ?? '—' }}</td>
                        <td>{{ $shipment->status?->label() ?? ucfirst((string) $shipment->status) }}</td>
                        <td>{{ number_format((int) $shipment->boxes_count) }}</td>
                        <td>{{ number_format((float) $shipment->total_weight_kg, 2) }} kg</td>
                        <td>{{ number_format((float) $shipment->shipping_cost_amount, 2) }}</td>
                        <td class="pr-5 text-right">
                            <x-tallui-button icon="o-eye" :link="route('inventory.shipments.show', ['recordId' => $shipment->getKey()])" class="btn-ghost btn-xs" label="Open" :responsive="true" />
                        </td>
                    </tr>
                @empty
                    <tr>
                            <td colspan="8" class="py-8">
                            <x-tallui-empty-state title="No shipments yet" description="Shipment records will appear here separately from transfers." icon="o-paper-airplane" size="sm" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-tallui-card>
</div>

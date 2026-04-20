<div>
<x-tallui-notification />

<x-tallui-page-header title="Logistics Transfers" subtitle="Manage dispatch and receipt across warehouses." icon="o-truck">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Transfers'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <div class="w-60">
                <x-tallui-input placeholder="Search transfers…" wire:model.live.debounce.300ms="search" class="input-sm" />
            </div>
            <div class="w-44">
                <x-tallui-select wire:model.live="status" class="select-sm">
                    <option value="">All statuses</option>
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-tallui-select>
            </div>
            <x-tallui-button label="New Transfer" icon="o-plus" :link="route('inventory.transfers.create')" class="btn-primary btn-sm" />
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
                    <th>Weight</th>
                    <th>Shipping Cost</th>
                    <th class="pr-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse ($transfers as $transfer)
                    <tr>
                        <td class="pl-5 font-mono font-semibold">{{ $transfer->transfer_number }}</td>
                        <td>{{ $transfer->fromWarehouse?->name ?? '—' }}</td>
                        <td>{{ $transfer->toWarehouse?->name ?? '—' }}</td>
                        <td>{{ $transfer->status?->label() ?? ucfirst((string) $transfer->status) }}</td>
                        <td>{{ number_format((float) $transfer->total_weight_kg, 2) }} kg</td>
                        <td>{{ number_format((float) $transfer->shipping_cost_amount, 2) }}</td>
                        <td class="pr-5 text-right">
                            <x-tallui-button icon="o-eye" :link="route('inventory.transfers.show', ['recordId' => $transfer->getKey()])" class="btn-ghost btn-xs" label="Open" :responsive="true" />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="py-8">
                            <x-tallui-empty-state title="No transfers yet" description="Start a logistics movement between warehouses." icon="o-truck" size="sm">
                                <x-tallui-button label="New Transfer" icon="o-plus" :link="route('inventory.transfers.create')" class="btn-primary btn-sm" />
                            </x-tallui-empty-state>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-tallui-card>
</div>

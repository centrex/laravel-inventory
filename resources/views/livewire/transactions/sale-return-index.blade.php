<div>
<x-tallui-page-header title="Sale Returns" subtitle="Track customer returns posted back into stock." icon="o-arrow-uturn-left">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[['label' => 'Inventory', 'href' => route('inventory.dashboard')], ['label' => 'Sale Returns']]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <div class="flex gap-2">
            <div class="w-60"><x-tallui-input placeholder="Search returns…" wire:model.live.debounce.300ms="search" class="input-sm" /></div>
            <x-tallui-button label="New Sale Return" icon="o-plus" :link="route('inventory.sale-returns.create')" class="btn-primary btn-sm" />
        </div>
    </x-slot:actions>
</x-tallui-page-header>
<x-tallui-card padding="none" :shadow="true">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead><tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300"><th class="pl-5">Number</th><th>Customer</th><th>Warehouse</th><th>Status</th><th class="pr-5 text-right">Action</th></tr></thead>
            <tbody>
                @foreach ($returns as $return)
                    <tr class="even:bg-base-200/50 hover:bg-base-200">
                        <td class="pl-5 font-mono">{{ $return->return_number }}</td>
                        <td>{{ $return->customer?->name ?? '—' }}</td>
                        <td>{{ $return->warehouse?->name ?? '—' }}</td>
                        <td>
                            <x-tallui-badge :type="\Centrex\Inventory\Support\StatusBadge::type($return->status)">
                                {{ ucfirst((string) $return->status) }}
                            </x-tallui-badge>
                        </td>
                        <td class="pr-5 text-right">
                            <x-tallui-button icon="o-eye" :link="route('inventory.sale-returns.show', ['recordId' => $return->getKey()])" class="btn-ghost btn-xs" label="Open" :responsive="true" />
                            <x-tallui-button icon="o-clock" wire:click="openAuditTrail(@js($return::class), {{ $return->getKey() }}, @js($return->return_number))" class="btn-ghost btn-xs" label="Audit" :responsive="true" />
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-tallui-card>
@include('inventory::livewire.shared.audit-trail-modal')
</div>

<div>
<x-tallui-notification />

<x-tallui-page-header
    :title="$documentLabel"
    :subtitle="$documentLabel === 'Requisitions' ? 'Track internal demand before purchase confirmation.' : 'Browse, edit, print, and export supplier purchase documents.'"
    icon="o-arrow-down-tray"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => $documentLabel],
        ]" />
    </x-slot:breadcrumbs>

    <x-slot:actions>
        <div class="w-60">
            <x-tallui-input placeholder="Search purchases…" wire:model.live.debounce.300ms="search" class="input-sm" />
        </div>
        <div class="w-44">
            <x-tallui-select wire:model.live="status" class="select-sm">
                <option value="">All statuses</option>
                @foreach ($statusOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-tallui-select>
        </div>
        <x-tallui-button
            :label="$documentLabel === 'Requisitions' ? 'New Requisition' : 'New Purchase'"
            icon="o-plus"
            :link="route($routeBase . '.create')"
            class="btn-primary btn-sm"
        />
    </x-slot:actions>
</x-tallui-page-header>

<x-tallui-card padding="none" :shadow="true">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-200/50 text-xs text-base-content/50 uppercase tracking-wide">
                    <th class="pl-5 py-3">Number</th>
                    <th class="py-3 whitespace-nowrap">Date</th>
                    <th class="py-3">Supplier</th>
                    <th class="py-3">Warehouse</th>
                    <th class="py-3">Status</th>
                    <th class="py-3">Currency</th>
                    <th class="py-3 text-right pr-4">Total</th>
                    <th class="py-3 pr-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse ($orders as $order)
                    @php
                        $statusClass = match($order->status?->value) {
                            'draft'     => 'bg-base-200 text-base-content/60',
                            'submitted' => 'bg-blue-100 text-blue-700',
                            'confirmed' => 'bg-violet-100 text-violet-700',
                            'partial'   => 'bg-amber-100 text-amber-700',
                            'received'  => 'bg-emerald-100 text-emerald-700',
                            'cancelled' => 'bg-red-100 text-red-600',
                            default     => 'bg-base-200 text-base-content/60',
                        };
                    @endphp
                    <tr class="hover:bg-base-50 transition-colors">
                        <td class="pl-5 py-3 font-mono text-sm font-semibold">
                            {{ $order->po_number }}
                        </td>
                        <td class="py-3 text-sm whitespace-nowrap text-base-content/70">
                            {{ $order->ordered_at?->format('M d, Y') ?? '—' }}
                        </td>
                        <td class="py-3 text-sm max-w-[160px] truncate" title="{{ $order->supplier?->name }}">
                            {{ $order->supplier?->name ?? '—' }}
                        </td>
                        <td class="py-3 text-sm max-w-[140px] truncate" title="{{ $order->warehouse?->name }}">
                            {{ $order->warehouse?->name ?? '—' }}
                        </td>
                        <td class="py-3">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusClass }}">
                                {{ $order->status?->label() ?? '—' }}
                            </span>
                        </td>
                        <td class="py-3 text-sm">
                            <span class="text-xs text-base-content/40 mr-0.5">{{ $order->currency }} </span>
                        </td>
                        <td class="py-3 pr-4 text-right font-mono text-sm font-medium whitespace-nowrap">
                            {{ number_format((float) $order->total_local, 2) }}
                        </td>
                        <td class="py-3 pr-5">
                            <div class="flex justify-end gap-1">
                                <x-tallui-button icon="o-eye" :link="route($routeBase . '.show', ['recordId' => $order->getKey()])" class="btn-ghost btn-xs" label="View" :responsive="true" wire:navigate />
                                <x-tallui-button icon="o-clock" wire:click="openAuditTrail(@js($order::class), {{ $order->getKey() }}, @js($order->po_number))" class="btn-ghost btn-xs" label="Audit" :responsive="true" />
                                <x-tallui-button icon="o-pencil-square" :link="route($routeBase . '.edit', ['recordId' => $order->getKey()])" class="btn-ghost btn-xs" label="Edit" :responsive="true" wire:navigate />
                                @if (Route::has('erp.documents.purchases.print'))
                                    <x-tallui-button icon="o-printer" :link="route('erp.documents.purchases.print', ['purchaseOrder' => $order->getKey()])" class="btn-ghost btn-xs" label="Print" :responsive="true" />
                                @endif
                                @if (Route::has('erp.documents.purchases.pdf'))
                                    <x-tallui-button icon="o-arrow-down-tray" :link="route('erp.documents.purchases.pdf', ['purchaseOrder' => $order->getKey()])" :no-wire-navigate="true" class="btn-ghost btn-xs" label="PDF" :responsive="true" />
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="py-12">
                            <x-tallui-empty-state
                                title="No purchase orders yet"
                                description="Create your first purchase order to manage supplier buying."
                                icon="o-document-arrow-down"
                                size="sm"
                            >
                                <x-tallui-button
                                    :label="$documentLabel === 'Requisitions' ? 'New Requisition' : 'New Purchase'"
                                    icon="o-plus"
                                    :link="route($routeBase . '.create')"
                                    class="btn-primary btn-sm"
                                />
                            </x-tallui-empty-state>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($orders->hasPages())
        <div class="border-t border-base-200 px-5 py-3">
            {{ $orders->links() }}
        </div>
    @endif
</x-tallui-card>

@include('inventory::livewire.shared.audit-trail-modal')
</div>

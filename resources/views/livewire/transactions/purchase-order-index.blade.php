<div>
<x-tallui-notification />

<x-tallui-page-header
    title="Purchase Orders"
    subtitle="Browse, edit, print, and export supplier purchase documents."
    icon="o-arrow-down-tray"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Purchase Orders'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
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
            <x-tallui-button label="New Purchase" icon="o-plus" :link="route('inventory.purchase-orders.create')" class="btn-primary btn-sm" />
        </div>
    </x-slot:actions>
</x-tallui-page-header>

<x-tallui-card padding="none" :shadow="true">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                    <th class="pl-5">Number</th>
                    <th>Date</th>
                    <th>Supplier</th>
                    <th>Warehouse</th>
                    <th>Status</th>
                    <th>Total</th>
                    <th class="pr-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse ($orders as $order)
                    <tr class="hover:bg-base-50">
                        <td class="pl-5 font-mono text-sm font-semibold">{{ $order->po_number }}</td>
                        <td class="text-sm">{{ $order->ordered_at?->format('M d, Y') ?? '—' }}</td>
                        <td class="text-sm">{{ $order->supplier?->name ?? '—' }}</td>
                        <td class="text-sm">{{ $order->warehouse?->name ?? '—' }}</td>
                        <td class="text-sm">{{ $order->status?->label() ?? '—' }}</td>
                        <td class="text-sm font-medium">{{ number_format((float) $order->total_local, 2) }}</td>
                        <td class="pr-5">
                            <div class="flex justify-end gap-1">
                                <x-tallui-button icon="o-eye" :link="route('inventory.purchase-orders.show', ['recordId' => $order->getKey()])" class="btn-ghost btn-xs" label="View" :responsive="true" />
                                <x-tallui-button icon="o-pencil-square" :link="route('inventory.purchase-orders.edit', ['recordId' => $order->getKey()])" class="btn-ghost btn-xs" label="Edit" :responsive="true" />
                                @if (Route::has('erp.documents.purchases.print'))
                                    <x-tallui-button icon="o-printer" :link="route('erp.documents.purchases.print', ['purchaseOrder' => $order->getKey()])" class="btn-ghost btn-xs" label="Print" :responsive="true" />
                                @endif
                                @if (Route::has('erp.documents.purchases.pdf'))
                                    <x-tallui-button icon="o-arrow-down-tray" :link="route('erp.documents.purchases.pdf', ['purchaseOrder' => $order->getKey()])" class="btn-ghost btn-xs" label="PDF" :responsive="true" />
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="py-8">
                            <x-tallui-empty-state title="No purchase orders yet" description="Create your first purchase order to manage supplier buying." icon="o-document-arrow-down" size="sm">
                                <x-tallui-button label="New Purchase" icon="o-plus" :link="route('inventory.purchase-orders.create')" class="btn-primary btn-sm" />
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
</div>

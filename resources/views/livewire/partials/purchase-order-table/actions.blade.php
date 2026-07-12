@php
    $routeBase = $row->document_type === 'requisition' ? 'inventory.requisitions' : 'inventory.purchase-orders';
@endphp
<div class="flex justify-end gap-1">
    <x-tallui-button icon="o-eye" :link="route($routeBase . '.show', ['recordId' => $row->getKey()])" class="btn-ghost btn-xs" label="View" :responsive="true" wire:navigate />
    @can('inventory.purchase-orders.audit')
    <x-tallui-button icon="o-clock" wire:click="$dispatch('purchase-order-table:audit', { id: {{ $row->getKey() }} })" class="btn-ghost btn-xs" label="Audit" :responsive="true" />
    @endcan
    @can('inventory.purchase-orders.manage')
    <x-tallui-button icon="o-pencil-square" :link="route($routeBase . '.edit', ['recordId' => $row->getKey()])" class="btn-ghost btn-xs" label="Edit" :responsive="true" wire:navigate />
    @endcan
    @if (Route::has('erp.documents.purchases.print'))
        <x-tallui-button icon="o-printer" :link="route('erp.documents.purchases.print', ['purchaseOrder' => $row->getKey()])" class="btn-ghost btn-xs" label="Print" :responsive="true" />
    @endif
    @if (Route::has('erp.documents.purchases.pdf'))
        <x-tallui-button icon="o-arrow-down-tray" :link="route('erp.documents.purchases.pdf', ['purchaseOrder' => $row->getKey()])" :no-wire-navigate="true" class="btn-ghost btn-xs" label="PDF" :responsive="true" />
    @endif
</div>

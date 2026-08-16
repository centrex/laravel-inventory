@php
    $routeBase = $row->document_type === 'quotation' ? 'inventory.quotations' : 'inventory.sale-orders';

    $moreActions = [];

    if (Route::has('erp.documents.sales.pdf')) {
        $moreActions[] = ['label' => 'PDF', 'icon' => 'o-arrow-down-tray', 'url' => route('erp.documents.sales.pdf', ['saleOrder' => $row->getKey()])];
    }

    if (Route::has('erp.documents.sales.sticker')) {
        $moreActions[] = ['label' => 'Sticker', 'icon' => 'o-tag', 'url' => route('erp.documents.sales.sticker', ['saleOrder' => $row->getKey()])];
    }

    if (auth()->user()?->can('inventory.sale-orders.audit')) {
        $moreActions[] = [
            'label'      => 'Audit',
            'icon'       => 'o-clock',
            'attributes' => ['wire:click' => "\$dispatch('sale-order-table:audit', { id: {$row->getKey()} })"],
        ];
    }
@endphp
<div class="flex justify-end items-center gap-1">
    <x-tallui-button icon="o-eye" :link="route($routeBase . '.show', ['recordId' => $row->getKey()])" class="btn-ghost btn-xs" label="View" :responsive="true" wire:navigate />
    @can('inventory.sale-orders.manage')
    <x-tallui-button icon="o-pencil-square" :link="route($routeBase . '.edit', ['recordId' => $row->getKey()])" class="btn-ghost btn-xs" label="Edit" :responsive="true" wire:navigate />
    @endcan
    @if (Route::has('erp.documents.sales.print'))
        <x-tallui-button icon="o-printer" :link="route('erp.documents.sales.print', ['saleOrder' => $row->getKey()])" class="btn-ghost btn-xs" label="Print" :responsive="true" />
    @endif
    @if ($moreActions)
        <x-tallui-dropdown position="bottom-end" :items="$moreActions">
            <x-slot:trigger>
                <x-tallui-button icon="o-ellipsis-vertical" class="btn-ghost btn-xs" />
            </x-slot:trigger>
        </x-tallui-dropdown>
    @endif
</div>

<div>
<x-tallui-notification />

<x-tallui-page-header
    :title="$documentLabel"
    :subtitle="$documentLabel === 'Quotations' ? 'Browse and refine customer quotes before confirmation.' : 'Browse, edit, print, and export customer sales documents.'"
    icon="o-shopping-cart"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => $documentLabel],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-button :label="$documentLabel === 'Quotations' ? 'New Quote' : 'New Sale'" icon="o-plus" :link="route($routeBase . '.create')" class="btn-primary btn-sm" />
    </x-slot:actions>
</x-tallui-page-header>

<livewire:inventory-sale-order-table :document-type="$documentType" wire:key="sale-order-table-{{ $documentType }}" />

@include('inventory::livewire.shared.audit-trail-modal')
</div>

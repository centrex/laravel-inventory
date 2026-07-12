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
        <x-tallui-button
            :label="$documentLabel === 'Requisitions' ? 'New Requisition' : 'New Purchase'"
            icon="o-plus"
            :link="route($routeBase . '.create')"
            class="btn-primary btn-sm"
        />
    </x-slot:actions>
</x-tallui-page-header>

<livewire:inventory-purchase-order-table :document-type="$documentType" wire:key="purchase-order-table-{{ $documentType }}" />

@include('inventory::livewire.shared.audit-trail-modal')
</div>

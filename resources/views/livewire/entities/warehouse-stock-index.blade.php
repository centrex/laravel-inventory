<div>
<x-tallui-notification />

<x-tallui-page-header title="Warehouse Stock" subtitle="Browse and manage warehouse stock." icon="o-table-cells">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Warehouse Stock'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-button label="New Warehouse Stock" icon="o-plus" :link="route('inventory.entities.warehouse-products.create')" class="btn-primary btn-sm" />
    </x-slot:actions>
</x-tallui-page-header>

<livewire:inventory-warehouse-stock-table />

@include('inventory::livewire.shared.audit-trail-modal')
</div>

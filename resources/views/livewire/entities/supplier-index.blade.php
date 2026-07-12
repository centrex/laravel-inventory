<div>
<x-tallui-notification />

<x-tallui-page-header title="Suppliers" subtitle="Browse and manage suppliers." icon="o-table-cells">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Suppliers'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-button label="New Supplier" icon="o-plus" :link="route('inventory.entities.suppliers.create')" class="btn-primary btn-sm" />
    </x-slot:actions>
</x-tallui-page-header>

<livewire:inventory-supplier-table />

@include('inventory::livewire.shared.audit-trail-modal')
</div>

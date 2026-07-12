<div>
<x-tallui-notification />

<x-tallui-page-header title="Customers" subtitle="Browse and manage customers." icon="o-table-cells">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Customers'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-button label="New Customer" icon="o-plus" :link="route('inventory.entities.customers.create')" class="btn-primary btn-sm" />
    </x-slot:actions>
</x-tallui-page-header>

<livewire:inventory-customer-table />

@include('inventory::livewire.shared.audit-trail-modal')
</div>

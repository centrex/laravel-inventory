<div>
<x-tallui-notification />

<x-tallui-page-header title="Products" subtitle="Browse and manage products." icon="o-table-cells">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Products'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-button label="New Product" icon="o-plus" :link="route('inventory.entities.products.create')" class="btn-primary btn-sm" />
    </x-slot:actions>
</x-tallui-page-header>

<livewire:inventory-product-table />

@include('inventory::livewire.shared.audit-trail-modal')
</div>

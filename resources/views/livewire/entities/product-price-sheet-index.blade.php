<div>
<x-tallui-notification />

<x-tallui-page-header
    title="Product Prices"
    subtitle="Browse and edit every price tier for a product at a warehouse, all at once."
    icon="o-tag"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Product Prices'],
        ]" />
    </x-slot:breadcrumbs>
</x-tallui-page-header>

<livewire:inventory-product-price-table />
</div>

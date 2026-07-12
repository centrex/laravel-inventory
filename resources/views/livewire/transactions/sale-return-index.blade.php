<div>
<x-tallui-page-header title="Sale Returns" subtitle="Track customer returns posted back into stock." icon="o-arrow-uturn-left">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[['label' => 'Inventory', 'href' => route('inventory.dashboard')], ['label' => 'Sale Returns']]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-button label="New Sale Return" icon="o-plus" :link="route('inventory.sale-returns.create')" class="btn-primary btn-sm" />
    </x-slot:actions>
</x-tallui-page-header>

<livewire:inventory-sale-return-table />

@include('inventory::livewire.shared.audit-trail-modal')
</div>

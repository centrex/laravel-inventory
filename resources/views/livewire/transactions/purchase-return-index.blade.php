<div>
<x-tallui-page-header title="Purchase Returns" subtitle="Track supplier returns posted out of stock." icon="o-arrow-uturn-right">
    <x-slot:breadcrumbs><x-tallui-breadcrumb :links="[['label' => 'Inventory', 'href' => route('inventory.dashboard')], ['label' => 'Purchase Returns']]" /></x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-button label="New Purchase Return" icon="o-plus" :link="route('inventory.purchase-returns.create')" class="btn-primary btn-sm" />
    </x-slot:actions>
</x-tallui-page-header>

<livewire:inventory-purchase-return-table />

@include('inventory::livewire.shared.audit-trail-modal')
</div>

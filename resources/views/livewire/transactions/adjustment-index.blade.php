<div>
<x-tallui-page-header title="Stock Adjustments" subtitle="History of count corrections, write-offs, and other stock variances." icon="o-scale">
    <x-slot:breadcrumbs><x-tallui-breadcrumb :links="[['label' => 'Inventory', 'href' => route('inventory.dashboard')], ['label' => 'Adjustments']]" /></x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-button label="New Adjustment" icon="o-plus" :link="route('inventory.adjustments.create')" class="btn-primary btn-sm" />
    </x-slot:actions>
</x-tallui-page-header>

<livewire:inventory-adjustment-table />

@include('inventory::livewire.shared.audit-trail-modal')
</div>

<div>
<x-tallui-page-header title="Stock Report" subtitle="Inventory valuation and low-stock positions across warehouses." icon="o-archive-box">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Reports', 'href' => route('inventory.reports.index')],
            ['label' => 'Stock'],
        ]" />
    </x-slot:breadcrumbs>
</x-tallui-page-header>

<x-tallui-tab :tabs="[
    ['id' => 'low_stock', 'label' => 'Low Stock', 'icon' => 'o-exclamation-triangle'],
    ['id' => 'stock_valuation', 'label' => 'Stock Valuation', 'icon' => 'o-banknotes'],
]" active="low_stock" variant="bordered">
<x-slot:low_stock>
    <livewire:inventory-low-stock-card lazy />
</x-slot:low_stock>

<x-slot:stock_valuation>
    <livewire:inventory-stock-valuation-card lazy />
</x-slot:stock_valuation>
</x-tallui-tab>
</div>

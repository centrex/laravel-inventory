<div>
<x-tallui-page-header title="Aging Report" subtitle="Stock aging and customer due aging in 30-day buckets." icon="o-clock">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Reports', 'href' => route('inventory.reports.index')],
            ['label' => 'Aging'],
        ]" />
    </x-slot:breadcrumbs>
</x-tallui-page-header>

<x-tallui-tab :tabs="[
    ['id' => 'stock_aging', 'label' => 'Stock Aging', 'icon' => 'o-archive-box'],
    ['id' => 'due_aging', 'label' => 'Due Aging', 'icon' => 'o-banknotes'],
]" active="stock_aging" variant="bordered">
<x-slot:stock_aging>
    <livewire:inventory-stock-aging-card lazy />
</x-slot:stock_aging>

<x-slot:due_aging>
    <livewire:inventory-due-aging-card lazy />
</x-slot:due_aging>
</x-tallui-tab>
</div>

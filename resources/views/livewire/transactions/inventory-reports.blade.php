<div>
<x-tallui-page-header title="Reports" subtitle="Sales, purchase, stock, and forecast reporting." icon="o-chart-bar">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Reports'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-button label="Customer Heat Map" icon="o-map" :link="route('inventory.reports.customer-heatmap')" class="btn-ghost btn-sm" />
    </x-slot:actions>
</x-tallui-page-header>

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <a href="{{ route('inventory.reports.sales') }}" wire:navigate>
        <x-tallui-card title="Sales Report" subtitle="Totals, discount, tax, collections, and product performance." icon="o-shopping-cart" :shadow="true" />
    </a>
    <a href="{{ route('inventory.reports.purchases') }}" wire:navigate>
        <x-tallui-card title="Purchase Report" subtitle="Totals, shipping, supplier payments, and product intake." icon="o-arrow-down-tray" :shadow="true" />
    </a>
    <a href="{{ route('inventory.reports.stock') }}" wire:navigate>
        <x-tallui-card title="Stock Report" subtitle="Valuation and low-stock positions across warehouses." icon="o-archive-box" :shadow="true" />
    </a>
    <a href="{{ route('inventory.reports.forecast') }}" wire:navigate>
        <x-tallui-card title="Sales Forecast" subtitle="Demand projection, cashflow outlook, and procurement requirement." icon="o-arrow-trending-up" :shadow="true" />
    </a>
</div>
</div>

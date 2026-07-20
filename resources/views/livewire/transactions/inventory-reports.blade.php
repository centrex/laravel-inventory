<div>
<x-tallui-page-header title="Reports" subtitle="Sales, purchase, stock, and forecast reporting." icon="o-chart-bar">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Reports'],
        ]" />
    </x-slot:breadcrumbs>
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
    <a href="{{ route('inventory.reports.aging') }}" wire:navigate>
        <x-tallui-card title="Aging Report" subtitle="Stock and customer due aging in 0-30/31-60/61-90/90+ day buckets." icon="o-clock" :shadow="true" />
    </a>
    <a href="{{ route('inventory.reports.forecast') }}" wire:navigate>
        <x-tallui-card title="Sales Forecast" subtitle="Demand projection, cashflow outlook, and procurement requirement." icon="o-arrow-trending-up" :shadow="true" />
    </a>
     <a href="{{ route('inventory.reports.customer-heatmap') }}" wire:navigate>
        <x-tallui-card title="Customer Heat Map" subtitle="Visual representation of customer activity and engagement." icon="o-map" :shadow="true" />
    </a>
</div>
</div>

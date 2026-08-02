<div>
<x-tallui-page-header title="Sales Report" subtitle="Sales totals, discount, tax, collections, and product performance." icon="o-shopping-cart">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Reports', 'href' => route('inventory.reports.index')],
            ['label' => 'Sales'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <div class="flex flex-wrap items-center gap-2">
            <div class="w-56">
                <div class="flex items-start gap-1">
                    <div class="flex-1" wire:key="sales-customer-select-{{ $customerId ?? 'none' }}">
                        <x-tallui-select
                            name="customerId"
                            wire:model.live="customerId"
                            :value="$customerId"
                            searchable
                            placeholder="All customers"
                            :options="$selectedCustomerOptions"
                            :search-url="parse_url(route('inventory.async-select', ['resource' => 'customers']), PHP_URL_PATH)"
                            class="select-sm"
                        />
                    </div>
                    @if ($customerId)
                        <x-tallui-button type="button" icon="o-x-mark" class="btn-ghost btn-sm mt-0.5" wire:click="$set('customerId', null)" :tooltip="'Clear customer'" />
                    @endif
                </div>
                @if ($customerLedgerUrl)
                    <a href="{{ $customerLedgerUrl }}" class="mt-1 inline-flex items-center gap-1 text-xs text-primary hover:underline" wire:navigate>
                        <x-tallui-icon name="o-book-open" class="h-3.5 w-3.5" />
                        View customer ledger
                    </a>
                @endif
            </div>
            <div class="w-56">
                <div class="flex items-start gap-1">
                    <div class="flex-1" wire:key="sales-product-select-{{ $productId ?? 'none' }}">
                        <x-tallui-select
                            name="productId"
                            wire:model.live="productId"
                            :value="$productId"
                            searchable
                            placeholder="All products"
                            :options="$selectedProductOptions"
                            :search-url="parse_url(route('inventory.async-select', ['resource' => 'products']), PHP_URL_PATH)"
                            class="select-sm"
                        />
                    </div>
                    @if ($productId)
                        <x-tallui-button type="button" icon="o-x-mark" class="btn-ghost btn-sm mt-0.5" wire:click="$set('productId', null)" :tooltip="'Clear product'" />
                    @endif
                </div>
            </div>
            <select wire:model.live="dateRange" wire:loading.attr="disabled" wire:target="dateRange" class="select select-bordered select-sm">
                <option value="this_month">This Month</option>
                <option value="last_month">Last Month</option>
                <option value="this_quarter">This Quarter</option>
                <option value="last_quarter">Last Quarter</option>
            </select>
            <x-tallui-input type="date" wire:model.live="startDate" wire:loading.attr="disabled" wire:target="startDate,endDate,customerId,productId" class="input-sm" />
            <x-tallui-input type="date" wire:model.live="endDate" wire:loading.attr="disabled" wire:target="startDate,endDate,customerId,productId" class="input-sm" />
            <span wire:loading wire:target="dateRange,startDate,endDate,customerId,productId" class="flex items-center gap-1 text-xs text-base-content/60">
                <span class="loading loading-spinner loading-xs"></span>
                Updating…
            </span>
            <x-tallui-button label="Export Excel" icon="o-arrow-down-tray" wire:click="exportExcel" spinner="exportExcel" class="btn-outline btn-sm" />
        </div>
    </x-slot:actions>
</x-tallui-page-header>

@php
    $filterKey = $startDate . '-' . $endDate . '-' . ($customerId ?? 'none') . '-' . ($productId ?? 'none');
@endphp

<x-tallui-tab :tabs="[
    ['id' => 'statistics', 'label' => 'Sale Statistics', 'icon' => 'o-chart-bar'],
    ['id' => 'recent', 'label' => 'Recent Sales', 'icon' => 'o-clock'],
    ['id' => 'sold_products', 'label' => 'Sold Products', 'icon' => 'o-cube'],
]" active="statistics" variant="bordered">
<x-slot:statistics>
    <livewire:inventory-sales-statistics-card
        :start-date="$startDate"
        :end-date="$endDate"
        :customer-id="$customerId"
        :product-id="$productId"
        lazy
        wire:key="sales-statistics-{{ $filterKey }}"
    />
</x-slot:statistics>

<x-slot:recent>
    <livewire:inventory-recent-sale-orders-card
        :start-date="$startDate"
        :end-date="$endDate"
        :customer-id="$customerId"
        :product-id="$productId"
        lazy
        wire:key="recent-sale-orders-{{ $filterKey }}"
    />
</x-slot:recent>

<x-slot:sold_products>
    <livewire:inventory-sold-products-card
        :start-date="$startDate"
        :end-date="$endDate"
        :customer-id="$customerId"
        :product-id="$productId"
        lazy
        wire:key="sold-products-{{ $filterKey }}"
    />
</x-slot:sold_products>
</x-tallui-tab>
</div>

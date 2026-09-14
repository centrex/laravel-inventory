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
        <x-tallui-button label="Export Excel" icon="o-arrow-down-tray" wire:click="exportExcel" spinner="exportExcel" class="btn-outline btn-sm" />
    </x-slot:actions>
</x-tallui-page-header>

@php
    $filterKey = $startDate . '-' . $endDate . '-' . ($customerId ?? 'none') . '-' . ($productId ?? 'none') . '-' . ($employeeId ?? 'none');
@endphp

<x-tallui-card :shadow="true" padding="normal" class="mb-4">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
        <h3 class="text-xs font-semibold uppercase tracking-wide text-base-content/60">Filters</h3>
        <span wire:loading wire:target="dateRange,startDate,endDate,customerId,productId,employeeId" class="flex items-center gap-1 text-xs text-base-content/60">
            <span class="loading loading-spinner loading-xs"></span>
            Updating…
        </span>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div>
            <div class="flex items-end gap-1" wire:key="sales-customer-select-{{ $customerId ?? 'none' }}">
                <div class="flex-1">
                    <x-tallui-select
                        name="customerId"
                        label="Customer"
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
                    <x-tallui-button type="button" icon="o-x-mark" class="btn-ghost btn-sm" wire:click="$set('customerId', null)" :tooltip="'Clear customer'" />
                @endif
            </div>
            @if ($customerLedgerUrl)
                <a href="{{ $customerLedgerUrl }}" class="mt-1 inline-flex items-center gap-1 text-xs text-primary hover:underline" wire:navigate>
                    <x-tallui-icon name="o-book-open" class="h-3.5 w-3.5" />
                    View customer ledger
                </a>
            @endif
        </div>

        <div>
            <div class="flex items-end gap-1" wire:key="sales-product-select-{{ $productId ?? 'none' }}">
                <div class="flex-1">
                    <x-tallui-select
                        name="productId"
                        label="Product"
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
                    <x-tallui-button type="button" icon="o-x-mark" class="btn-ghost btn-sm" wire:click="$set('productId', null)" :tooltip="'Clear product'" />
                @endif
            </div>
        </div>

        <div>
            <label class="label pb-1" for="salesReportEmployee"><span class="label-text text-xs">Employee</span></label>
            <select id="salesReportEmployee" wire:model.live="employeeId" wire:loading.attr="disabled" wire:target="employeeId" class="select select-bordered select-sm w-full">
                <option value="">All employees</option>
                @foreach ($employeeOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="label pb-1" for="salesReportDateRange"><span class="label-text text-xs">Date Range</span></label>
            <select id="salesReportDateRange" wire:model.live="dateRange" wire:loading.attr="disabled" wire:target="dateRange" class="select select-bordered select-sm w-full">
                <option value="this_month">This Month</option>
                <option value="last_month">Last Month</option>
                <option value="this_quarter">This Quarter</option>
                <option value="last_quarter">Last Quarter</option>
            </select>
        </div>

        <div>
            <x-tallui-input type="date" name="startDate" label="Start Date" wire:model.live="startDate" wire:loading.attr="disabled" wire:target="startDate,endDate,customerId,productId,employeeId" class="input-sm w-full" />
        </div>

        <div>
            <x-tallui-input type="date" name="endDate" label="End Date" wire:model.live="endDate" wire:loading.attr="disabled" wire:target="startDate,endDate,customerId,productId,employeeId" class="input-sm w-full" />
        </div>
    </div>
</x-tallui-card>

<x-tallui-tab :tabs="[
    ['id' => 'statistics', 'label' => 'Sale Statistics', 'icon' => 'o-chart-bar'],
    ['id' => 'recent', 'label' => 'Recent Sales', 'icon' => 'o-clock'],
    ['id' => 'sold_products', 'label' => 'Sold Products', 'icon' => 'o-cube'],
    ['id' => 'returned_products', 'label' => 'Returned Products', 'icon' => 'o-arrow-uturn-left'],
]" active="statistics" variant="bordered">
<x-slot:statistics>
    <livewire:inventory-sales-statistics-card
        :start-date="$startDate"
        :end-date="$endDate"
        :customer-id="$customerId"
        :product-id="$productId"
        :employee-id="$employeeId"
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
        :employee-id="$employeeId"
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
        :employee-id="$employeeId"
        lazy
        wire:key="sold-products-{{ $filterKey }}"
    />
</x-slot:sold_products>

<x-slot:returned_products>
    <livewire:inventory-returned-products-card
        :start-date="$startDate"
        :end-date="$endDate"
        :customer-id="$customerId"
        :product-id="$productId"
        :employee-id="$employeeId"
        lazy
        wire:key="returned-products-{{ $filterKey }}"
    />
</x-slot:returned_products>
</x-tallui-tab>
</div>

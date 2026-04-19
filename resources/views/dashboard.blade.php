<x-layouts::app>
<x-tallui-notification />

<x-tallui-page-header
    title="Inventory"
    subtitle="Stock, pricing, warehouses, vendors, customers, and order workflows."
    icon="o-building-storefront"
>
    <x-slot:actions>
        @if(Route::has('payroll.entities.employees.index'))
        <x-tallui-button label="Employees" icon="o-identification" :link="route('payroll.entities.employees.index')" class="btn-outline btn-sm" />
        @endif
        <x-tallui-button label="Purchase" icon="o-arrow-down-tray" :link="route('inventory.purchase-orders.create')" class="btn-outline btn-sm" />
        <x-tallui-button label="Sale" icon="o-shopping-cart" :link="route('inventory.sale-orders.create')" class="btn-outline btn-sm" />
        <x-tallui-button label="POS" icon="o-device-phone-mobile" :link="route('inventory.pos.index')" class="btn-outline btn-sm" />
        <x-tallui-button label="Transfer" icon="o-arrows-right-left" :link="route('inventory.transfers.create')" class="btn-outline btn-sm" />
        @if(Route::has('payroll.entries.index'))
        <x-tallui-button label="Payroll" icon="o-users" :link="route('payroll.entries.index')" class="btn-outline btn-sm" />
        @endif
        <x-tallui-button label="Adjustment" icon="o-scale" :link="route('inventory.adjustments.create')" class="btn-primary btn-sm" />
    </x-slot:actions>
</x-tallui-page-header>

{{-- Stats row --}}
<div class="stats shadow w-full mb-6">
    <x-tallui-stat
        title="Master Modules"
        :value="count($entities)"
        desc="Configured entity screens"
        icon="o-rectangle-stack"
    />
    <x-tallui-stat
        title="Transaction Workflows"
        value="8"
        desc="Employees · PO · SO · POS · Transfer · Adjustment · Expense · Payroll"
        icon="o-bolt"
    />
    <x-tallui-stat
        title="Stock Value"
        :value="number_format((float) $totalStockValue, 2)"
        desc="Total inventory value across warehouses"
        icon="o-banknotes"
    />
</div>

<x-tallui-card
    title="Warehouse Stock Value"
    subtitle="Weighted-average stock value by warehouse."
    icon="o-building-office-2"
    :shadow="true"
    class="mb-6"
>
    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
        @forelse ($warehouseStockValues as $warehouseStock)
            <div class="rounded-2xl border border-base-200 bg-base-100 p-4">
                <div class="text-sm font-semibold text-base-content">{{ $warehouseStock['name'] }}</div>
                <div class="mt-2 text-2xl font-bold text-primary">
                    {{ number_format((float) $warehouseStock['stock_value'], 2) }}
                </div>
                <div class="mt-1 text-xs text-base-content/50">{{ $warehouseStock['currency'] }} weighted stock value</div>
            </div>
        @empty
            <div class="col-span-full">
                <x-tallui-empty-state
                    title="No warehouses yet"
                    description="Create warehouses and stock records to view valuation by location."
                    icon="o-building-office"
                    size="sm"
                />
            </div>
        @endforelse
    </div>
</x-tallui-card>

{{-- Quick actions --}}
<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3 mb-6">
    @if(Route::has('payroll.entities.employees.index'))
    <a href="{{ route('payroll.entities.employees.index') }}"
       class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-identification class="w-7 h-7 text-primary" />
        <span class="text-sm font-medium">Employees</span>
    </a>
    @endif
    <a href="{{ route('inventory.purchase-orders.create') }}"
       class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-arrow-down-tray class="w-7 h-7 text-primary" />
        <span class="text-sm font-medium">New Purchase</span>
    </a>
    <a href="{{ route('inventory.sale-orders.create') }}"
       class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-shopping-cart class="w-7 h-7 text-success" />
        <span class="text-sm font-medium">New Sale</span>
    </a>
    <a href="{{ route('inventory.pos.index') }}"
       class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-device-phone-mobile class="w-7 h-7 text-secondary" />
        <span class="text-sm font-medium">POS Terminal</span>
    </a>
    <a href="{{ route('inventory.transfers.create') }}"
       class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-arrows-right-left class="w-7 h-7 text-info" />
        <span class="text-sm font-medium">New Transfer</span>
    </a>
    <a href="{{ route('inventory.adjustments.create') }}"
       class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-scale class="w-7 h-7 text-warning" />
        <span class="text-sm font-medium">Adjustment</span>
    </a>
    @if(Route::has('payroll.entries.index'))
    <a href="{{ route('payroll.entries.index') }}"
       class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-users class="w-7 h-7 text-accent" />
        <span class="text-sm font-medium">Payroll</span>
    </a>
    @endif
</div>

{{-- Master data entities --}}
<x-tallui-card title="Master Data" subtitle="Open CRUD screens for inventory master tables." icon="o-squares-2x2" :shadow="true">
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
        @foreach ($entities as $entity => $definition)
            <a href="{{ route("inventory.entities.{$entity}.index") }}"
               class="flex flex-col gap-1 p-4 rounded-xl border border-base-200 bg-base-100 hover:border-primary hover:bg-base-200 transition group">
                <div class="flex items-center justify-between mb-1">
                    <x-heroicon-o-folder class="w-5 h-5 text-base-content/40 group-hover:text-primary transition" />
                    <x-heroicon-o-arrow-top-right-on-square class="w-4 h-4 text-base-content/30 group-hover:text-primary transition" />
                </div>
                <span class="text-sm font-semibold text-base-content leading-tight">{{ $definition['label'] }}</span>
                <span class="text-xs text-base-content/50">Manage records</span>
            </a>
        @endforeach

        {{-- Expenses shortcut --}}
        @if(Route::has('accounting.expenses'))
        <a href="{{ route('accounting.expenses') }}"
           class="flex flex-col gap-1 p-4 rounded-xl border border-base-200 bg-base-100 hover:border-primary hover:bg-base-200 transition group">
            <div class="flex items-center justify-between mb-1">
                <x-heroicon-o-credit-card class="w-5 h-5 text-base-content/40 group-hover:text-primary transition" />
                <x-heroicon-o-arrow-top-right-on-square class="w-4 h-4 text-base-content/30 group-hover:text-primary transition" />
            </div>
            <span class="text-sm font-semibold text-base-content leading-tight">Expenses</span>
            <span class="text-xs text-base-content/50">Track spend</span>
        </a>
        @endif
    </div>
</x-tallui-card>
</x-layouts::app>

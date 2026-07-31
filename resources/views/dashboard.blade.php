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
        @can('inventory.purchase-orders.view')
        <x-tallui-button label="Purchase" icon="o-arrow-down-tray" :link="route('inventory.purchase-orders.index')" class="btn-outline btn-sm" />
        @endcan
        @can('inventory.purchase-orders.view')
        <x-tallui-button label="Requisition" icon="o-clipboard-document-check" :link="route('inventory.requisitions.index')" class="btn-outline btn-sm" />
        @endcan
        @can('inventory.sale-orders.view')
        <x-tallui-button label="Sale" icon="o-shopping-cart" :link="route('inventory.sale-orders.index')" class="btn-outline btn-sm" />
        @endcan
        @can('inventory.sale-orders.view')
        <x-tallui-button label="Quotation" icon="o-document-duplicate" :link="route('inventory.quotations.index')" class="btn-outline btn-sm" />
        @endcan
        @can('inventory.channels.checkout')
        <x-tallui-button label="POS" icon="o-device-phone-mobile" :link="route('inventory.pos.index')" class="btn-outline btn-sm" target="_blank" />
        @endcan
        @can('inventory.transfers.view')
        <x-tallui-button label="Transfer" icon="o-arrows-right-left" :link="route('inventory.transfers.index')" class="btn-outline btn-sm" />
        @endcan
        @can('inventory.shipments.view')
        <x-tallui-button label="Shipment" icon="o-paper-airplane" :link="route('inventory.shipments.index')" class="btn-outline btn-sm" />
        @endcan
        @can('inventory.master-data.view')
        <x-tallui-button label="Warehouse Stocks" icon="o-cube" :link="route('inventory.entities.warehouse-products.index')" class="btn-outline btn-sm" />
        @endcan
        @if ($canViewForecast)
        <x-tallui-button label="Reports" icon="o-chart-bar" :link="route('inventory.reports.index')" class="btn-outline btn-sm" />
        @endif
        @if(Route::has('payroll.entries.index'))
        <x-tallui-button label="Payroll" icon="o-users" :link="route('payroll.entries.index')" class="btn-outline btn-sm" />
        @endif
        @can('inventory.adjustments.create')
        <x-tallui-button label="Adjustment" icon="o-scale" :link="route('inventory.adjustments.create')" class="btn-primary btn-sm" />
        @endcan
    </x-slot:actions>
</x-tallui-page-header>

    {{-- Quick actions (collapsible, preference saved to localStorage) --}}
    <div x-data="{
        open: localStorage.getItem('inv_quick_actions') === 'true',
        toggle() { this.open = !this.open; localStorage.setItem('inv_quick_actions', this.open ? 'true' : 'false'); }
    }">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-semibold text-base-content/40 uppercase tracking-widest">Quick Actions</span>
            <button @click="toggle()" class="btn btn-ghost btn-xs gap-1 text-base-content/50 hover:text-base-content">
                <span x-text="open ? 'Hide' : 'Show'"></span>
                <x-heroicon-o-chevron-up x-show="open" class="w-3 h-3" x-cloak />
                <x-heroicon-o-chevron-down x-show="!open" class="w-3 h-3" x-cloak />
            </button>
        </div>
        <div x-show="open" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-1">
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-3 mb-6">
        @if(Route::has('payroll.entities.employees.index'))
        <a href="{{ route('payroll.entities.employees.index') }}"
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
            <x-heroicon-o-identification class="w-7 h-7 text-primary" />
            <span class="text-sm font-medium">Employees</span>
        </a>
        @endif
        @can('inventory.purchase-orders.create')
        <a href="{{ route('inventory.purchase-orders.create') }}"
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
            <x-heroicon-o-arrow-down-tray class="w-7 h-7 text-primary" />
            <span class="text-sm font-medium">New Purchase</span>
        </a>
        @endcan
        @can('inventory.purchase-orders.create')
        <a href="{{ route('inventory.requisitions.create') }}"
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
            <x-heroicon-o-clipboard-document-check class="w-7 h-7 text-warning" />
            <span class="text-sm font-medium">Requisition</span>
        </a>
        @endcan
        @can('inventory.sale-orders.create')
        <a href="{{ route('inventory.sale-orders.create') }}"
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
            <x-heroicon-o-shopping-cart class="w-7 h-7 text-success" />
            <span class="text-sm font-medium">New Sale</span>
        </a>
        @endcan
        @can('inventory.sale-orders.create')
        <a href="{{ route('inventory.quotations.create') }}"
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
            <x-heroicon-o-document-duplicate class="w-7 h-7 text-info" />
            <span class="text-sm font-medium">Quotation</span>
        </a>
        @endcan
        @can('inventory.channels.checkout')
        <a href="{{ route('inventory.pos.index') }}" target="_blank"
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
            <x-heroicon-o-device-phone-mobile class="w-7 h-7 text-secondary" />
            <span class="text-sm font-medium">POS Terminal</span>
        </a>
        @endcan
        @can('inventory.transfers.view')
        <a href="{{ route('inventory.transfers.index') }}"
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
            <x-heroicon-o-arrows-right-left class="w-7 h-7 text-info" />
            <span class="text-sm font-medium">Transfers</span>
        </a>
        @endcan
        @can('inventory.shipments.view')
        <a href="{{ route('inventory.shipments.index') }}"
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
            <x-heroicon-o-paper-airplane class="w-7 h-7 text-primary" />
            <span class="text-sm font-medium">Shipments</span>
        </a>
        @endcan
        @can('inventory.master-data.view')
        <a href="{{ route('inventory.entities.warehouse-products.index') }}"
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
            <x-heroicon-o-cube class="w-7 h-7 text-success" />
            <span class="text-sm font-medium">Warehouse Stocks</span>
        </a>
        @endcan
        @if ($canViewForecast)
        <a href="{{ route('inventory.reports.index') }}"
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
            <x-heroicon-o-chart-bar class="w-7 h-7 text-secondary" />
            <span class="text-sm font-medium">Reports</span>
        </a>
        <a href="{{ route('inventory.reports.customer-heatmap') }}"
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
            <x-heroicon-o-map class="w-7 h-7 text-accent" />
            <span class="text-sm font-medium">Heat Map</span>
        </a>
        @endif
        @can('inventory.adjustments.create')
        <a href="{{ route('inventory.adjustments.create') }}"
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
            <x-heroicon-o-scale class="w-7 h-7 text-warning" />
            <span class="text-sm font-medium">Adjustment</span>
        </a>
        @endcan
        @if(Route::has('payroll.entries.index'))
        <a href="{{ route('payroll.entries.index') }}"
        class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
            <x-heroicon-o-users class="w-7 h-7 text-accent" />
            <span class="text-sm font-medium">Payroll</span>
        </a>
        @endif
    </div>
        </div>
    </div>

<x-tallui-tab
    :tabs="[
        ['id' => 'overview', 'label' => 'Overview', 'icon' => 'o-squares-2x2'],
        ['id' => 'forecast', 'label' => 'Forecast', 'icon' => 'o-arrow-trending-up'],
        ['id' => 'target', 'label' => 'Sales Target', 'icon' => 'o-trophy'],
    ]"
    :active="request('dashboard_tab', 'overview')"
    variant="bordered"
    size="sm"
    class="mb-2"
>
    <x-slot:overview>
        {{-- <div class="stats shadow w-full mb-6">
            <x-tallui-stat
                title="Master Modules"
                :value="count($entities)"
                desc="Configured entity screens"
                icon="o-rectangle-stack"
            />
            <x-tallui-stat
                title="Transaction Workflows"
                value="9"
                desc="Employees · PO · SO · POS · Transfer · Shipment · Adjustment · Expense · Payroll"
                icon="o-bolt"
            />
            <x-tallui-stat
                title="Stock Value"
                :value="number_format((float) $totalStockValue, 2)"
                desc="Total inventory value across warehouses"
                icon="o-banknotes"
            />
        </div> --}}

        {{-- Sales Order Trend, Draft Sale Orders, Sales by Price Tier, Sales by Employee, and
             Warehouse Stock each used to be computed inline here on every dashboard load.
             They're now their own lazy Livewire components — `lazy` alone defers each one
             off the initial page response and shows its placeholder() until its own AJAX
             fetch resolves; no x-if tab-gating needed since the Overview tab is the one
             visible by default (contrast with the Forecast/Sales Target tabs below). --}}
        <div class="space-y-4">
            <livewire:inventory-sales-trend-card lazy />
            <livewire:inventory-draft-sale-orders-card lazy />
            <livewire:inventory-sales-by-price-tier-card lazy />
            <livewire:inventory-sales-by-employee-card lazy />
            <livewire:inventory-warehouse-stock-card lazy />
        </div>
    </x-slot:overview>

    <x-slot:forecast>
        @if ($canViewForecast)
            {{-- Not mounted (and its lazy AJAX fetch not fired) until this tab is actually
                 clicked — x-tallui-tab's named-slot panels are `x-show`-toggled, not removed
                 from the DOM, so a plain `lazy` tag here would still fetch on page load. --}}
            <template x-if="activeTab === 'forecast'">
                <div>
                    <livewire:inventory-forecast-card lazy />
                </div>
            </template>
        @else
            <x-tallui-card
                title="Forecast Access Required"
                subtitle="Forecasting is available to users with the inventory reports permission."
                icon="o-lock-closed"
                :shadow="true"
                class="mb-6"
            >
                <x-tallui-empty-state
                    title="No forecast access"
                    description="Ask an administrator to grant the `inventory.reports.view` permission to open sales, customer, cashflow, and product requirement forecasts."
                    icon="o-shield-exclamation"
                    size="sm"
                />
            </x-tallui-card>
        @endif
    </x-slot:forecast>

    <x-slot:target>
        @if ($canViewForecast)
            {{-- Inputs read straight from the query string (this form does a full GET reload
                 on submit — same values SalesTargetCalculator was given last), so this stays
                 in the shell and renders immediately; it doesn't need the heavy computation
                 below to know what to show. --}}
            <form method="GET" action="{{ route('inventory.dashboard') }}" class="mb-6">
                <input type="hidden" name="dashboard_tab" value="target">
                <x-tallui-card
                    title="Sales Target Inputs"
                    subtitle="Tune the target period, gross margin, net profit, growth, and expense allocation."
                    icon="o-adjustments-horizontal"
                    :shadow="true"
                >
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-6">
                        <label class="form-control">
                            <span class="label-text text-xs font-semibold">Lookback Days</span>
                            <input type="number" min="7" max="730" name="target_lookback_days" value="{{ request('target_lookback_days', 90) }}" class="input input-bordered input-sm">
                        </label>
                        <label class="form-control">
                            <span class="label-text text-xs font-semibold">Target Days</span>
                            <input type="number" min="1" max="366" name="target_days" value="{{ request('target_days', 30) }}" class="input input-bordered input-sm">
                        </label>
                        <label class="form-control">
                            <span class="label-text text-xs font-semibold">Gross Margin %</span>
                            <input type="number" min="1" max="95" step="0.01" name="target_gross_margin_pct" value="{{ request('target_gross_margin_pct', 25) }}" class="input input-bordered input-sm">
                        </label>
                        <label class="form-control">
                            <span class="label-text text-xs font-semibold">Net Profit %</span>
                            <input type="number" min="0" max="80" step="0.01" name="target_net_margin_pct" value="{{ request('target_net_margin_pct', 10) }}" class="input input-bordered input-sm">
                        </label>
                        <label class="form-control">
                            <span class="label-text text-xs font-semibold">Growth %</span>
                            <input type="number" min="0" max="200" step="0.01" name="target_growth_pct" value="{{ request('target_growth_pct', 0) }}" class="input input-bordered input-sm">
                        </label>
                        <label class="form-control">
                            <span class="label-text text-xs font-semibold">Expense Allocation %</span>
                            <input type="number" min="0" max="100" step="0.01" name="target_expense_allocation_pct" value="{{ request('target_expense_allocation_pct', 100) }}" class="input input-bordered input-sm">
                        </label>
                    </div>
                    <div class="mt-4 flex justify-end">
                        <x-tallui-button type="submit" label="Recalculate" icon="o-calculator" class="btn-primary btn-sm" />
                    </div>
                </x-tallui-card>
            </form>

            <template x-if="activeTab === 'target'">
                <div>
                    <livewire:inventory-sales-target-card lazy />
                </div>
            </template>
        @else
            <x-tallui-card
                title="Sales Target Access Required"
                subtitle="Sales targets are available to users with the inventory reports permission."
                icon="o-lock-closed"
                :shadow="true"
                class="mb-6"
            >
                <x-tallui-empty-state
                    title="No target access"
                    description="Ask an administrator to grant the `inventory.reports.view` permission to open sales target planning."
                    icon="o-shield-exclamation"
                    size="sm"
                />
            </x-tallui-card>
        @endif
    </x-slot:target>
</x-tallui-tab>

{{-- Master data entities --}}
<x-tallui-card title="Master Data" subtitle="Open CRUD screens for inventory master tables." icon="o-squares-2x2" :shadow="true">
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
        @can('inventory.master-data.view')
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
        @endcan

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

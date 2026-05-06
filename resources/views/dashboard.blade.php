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
        <x-tallui-button label="Requisition" icon="o-clipboard-document-check" :link="route('inventory.requisitions.create')" class="btn-outline btn-sm" />
        <x-tallui-button label="Sale" icon="o-shopping-cart" :link="route('inventory.sale-orders.create')" class="btn-outline btn-sm" />
        <x-tallui-button label="Quotation" icon="o-document-duplicate" :link="route('inventory.quotations.create')" class="btn-outline btn-sm" />
        <x-tallui-button label="POS" icon="o-device-phone-mobile" :link="route('inventory.pos.index')" class="btn-outline btn-sm" target="_blank" />
        <x-tallui-button label="Transfer" icon="o-arrows-right-left" :link="route('inventory.transfers.index')" class="btn-outline btn-sm" />
        @if ($canViewForecast)
        <x-tallui-button label="Reports" icon="o-chart-bar" :link="route('inventory.reports.index')" class="btn-outline btn-sm" />
        @endif
        @if(Route::has('payroll.entries.index'))
        <x-tallui-button label="Payroll" icon="o-users" :link="route('payroll.entries.index')" class="btn-outline btn-sm" />
        @endif
        <x-tallui-button label="Adjustment" icon="o-scale" :link="route('inventory.adjustments.create')" class="btn-primary btn-sm" />
    </x-slot:actions>
</x-tallui-page-header>

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
    </x-slot:overview>

    <x-slot:forecast>
        @if ($canViewForecast)
            <div class="stats shadow w-full mb-6">
                <x-tallui-stat
                    title="Forecast Demand"
                    :value="number_format((float) data_get($forecast, 'summary.forecast_qty', 0), 2)"
                    :desc="data_get($forecast, 'window.forecast_days', 90) . ' day projected quantity'"
                    icon="o-arrow-trending-up"
                />
                <x-tallui-stat
                    title="Holistic Requirement"
                    :value="number_format((float) data_get($forecast, 'summary.required_qty', 0), 2)"
                    :desc="data_get($forecast, 'summary.products_at_risk', 0) . ' products need replenishment'"
                    icon="o-cube"
                />
                <x-tallui-stat
                    title="Forecast Cash Net"
                    :value="number_format((float) data_get($forecast, 'summary.forecast_cash_net', 0), 2)"
                    desc="Projected collections less procurement cash"
                    icon="o-presentation-chart-line"
                />
            </div>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-3 mb-6">
                <x-tallui-card
                    title="Sales Forecast"
                    subtitle="Projected quantity, revenue, and cash impact from recent order behavior."
                    icon="o-arrow-trending-up"
                    :shadow="true"
                >
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between"><span class="text-base-content/60">Lookback Window</span><strong>{{ data_get($forecast, 'window.lookback_days', 0) }} days</strong></div>
                        <div class="flex justify-between"><span class="text-base-content/60">Forecast Horizon</span><strong>{{ data_get($forecast, 'window.forecast_days', 0) }} days</strong></div>
                        <div class="flex justify-between"><span class="text-base-content/60">Projected Quantity</span><strong>{{ number_format((float) data_get($forecast, 'summary.forecast_qty', 0), 2) }}</strong></div>
                        <div class="flex justify-between"><span class="text-base-content/60">Projected Revenue</span><strong>{{ number_format((float) data_get($forecast, 'summary.forecast_revenue', 0), 2) }}</strong></div>
                        <div class="flex justify-between"><span class="text-base-content/60">Expected Cash In</span><strong class="text-success">{{ number_format((float) data_get($forecast, 'summary.forecast_cash_in', 0), 2) }}</strong></div>
                        <div class="flex justify-between"><span class="text-base-content/60">Expected Cash Out</span><strong>{{ number_format((float) data_get($forecast, 'summary.forecast_cash_out', 0), 2) }}</strong></div>
                        <div class="flex justify-between"><span class="text-base-content/60">Net Cash</span><strong class="{{ (float) data_get($forecast, 'summary.forecast_cash_net', 0) >= 0 ? 'text-success' : 'text-error' }}">{{ number_format((float) data_get($forecast, 'summary.forecast_cash_net', 0), 2) }}</strong></div>
                    </div>
                </x-tallui-card>

                <x-tallui-card
                    title="Top Product Risks"
                    subtitle="Products with the biggest upcoming demand gap and stockout timeline."
                    icon="o-exclamation-triangle"
                    :shadow="true"
                    class="xl:col-span-2"
                >
                    <div class="overflow-x-auto">
                        <table class="table table-sm w-full">
                            <thead>
                                <tr class="bg-base-50 text-xs uppercase text-base-content/50">
                                    <th>Product</th>
                                    <th>Forecast Qty</th>
                                    <th>Available Soon</th>
                                    <th>Gap</th>
                                    <th>Cover</th>
                                    <th>Stockout</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse (collect(data_get($forecast, 'products', []))->take(6) as $product)
                                    <tr>
                                        <td>
                                            <div class="font-medium">{{ $product['product_name'] }}</div>
                                            <div class="text-xs text-base-content/50">{{ $product['sku'] ?: '—' }}</div>
                                        </td>
                                        <td>{{ number_format((float) $product['forecast_qty'], 2) }}</td>
                                        <td>{{ number_format((float) $product['available_soon_qty'], 2) }}</td>
                                        <td class="{{ (float) $product['forecast_gap_qty'] > 0 ? 'text-warning font-semibold' : 'text-success' }}">{{ number_format((float) $product['forecast_gap_qty'], 2) }}</td>
                                        <td>{{ $product['days_of_cover'] !== null ? number_format((float) $product['days_of_cover'], 1) . ' days' : '—' }}</td>
                                        <td>{{ $product['stockout_date'] ?: 'Covered' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-sm text-base-content/60">No forecast data available yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-tallui-card>
            </div>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-2 mb-6">
                <x-tallui-card
                    title="Customer Forecast"
                    subtitle="Projected customer-wise demand based on recent order patterns."
                    icon="o-user-group"
                    :shadow="true"
                >
                    <div class="space-y-3 text-sm">
                        @forelse (data_get($forecast, 'customers', []) as $customer)
                            <div class="flex items-center justify-between gap-3 rounded-xl border border-base-200 bg-base-100 p-3">
                                <div>
                                    <div class="font-medium">{{ $customer['customer_name'] }}</div>
                                    <div class="text-xs text-base-content/60">{{ $customer['zone'] ?? 'Unassigned' }} · {{ $customer['area'] ?? 'Unassigned' }} · {{ $customer['demographic'] ?? 'Unassigned' }} · {{ $customer['segment'] ?? 'New' }}</div>
                                    <div class="text-xs text-base-content/60">{{ $customer['orders_count'] }} orders · {{ $customer['products_count'] }} products</div>
                                </div>
                                <div class="text-right">
                                    <div class="font-semibold">{{ number_format((float) $customer['forecast_revenue'], 2) }}</div>
                                    <div class="text-xs text-base-content/60">{{ number_format((float) $customer['forecast_qty'], 2) }} qty</div>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-base-content/60">No customer forecast available yet.</p>
                        @endforelse
                    </div>
                </x-tallui-card>

                <x-tallui-card
                    title="Forecast Timeline"
                    subtitle="Holistic monthly demand and cash requirement for inventory planning."
                    icon="o-calendar-days"
                    :shadow="true"
                >
                    <div class="space-y-3 text-sm">
                        @forelse (data_get($forecast, 'timeline.categories', []) as $index => $month)
                            <div class="rounded-xl border border-base-200 bg-base-100 p-3">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="font-medium">{{ $month }}</div>
                                    <div class="text-xs text-base-content/60">Forecast bucket</div>
                                </div>
                                <div class="mt-2 grid grid-cols-2 gap-2 text-xs md:grid-cols-5">
                                    <div><span class="text-base-content/50">Qty</span><div class="font-semibold">{{ number_format((float) data_get($forecast, "timeline.series.0.data.$index", 0), 2) }}</div></div>
                                    <div><span class="text-base-content/50">Revenue</span><div class="font-semibold">{{ number_format((float) data_get($forecast, "timeline.series.1.data.$index", 0), 2) }}</div></div>
                                    <div><span class="text-base-content/50">Cash In</span><div class="font-semibold text-success">{{ number_format((float) data_get($forecast, "timeline.series.2.data.$index", 0), 2) }}</div></div>
                                    <div><span class="text-base-content/50">Cash Out</span><div class="font-semibold">{{ number_format((float) data_get($forecast, "timeline.series.3.data.$index", 0), 2) }}</div></div>
                                    <div><span class="text-base-content/50">Net</span><div class="font-semibold {{ (float) data_get($forecast, "timeline.series.4.data.$index", 0) >= 0 ? 'text-success' : 'text-error' }}">{{ number_format((float) data_get($forecast, "timeline.series.4.data.$index", 0), 2) }}</div></div>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-base-content/60">No timeline forecast available yet.</p>
                        @endforelse
                    </div>
                </x-tallui-card>
            </div>
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
                            <input type="number" min="7" max="730" name="target_lookback_days" value="{{ data_get($salesTarget, 'window.lookback_days', 90) }}" class="input input-bordered input-sm">
                        </label>
                        <label class="form-control">
                            <span class="label-text text-xs font-semibold">Target Days</span>
                            <input type="number" min="1" max="366" name="target_days" value="{{ data_get($salesTarget, 'window.target_days', 30) }}" class="input input-bordered input-sm">
                        </label>
                        <label class="form-control">
                            <span class="label-text text-xs font-semibold">Gross Margin %</span>
                            <input type="number" min="1" max="95" step="0.01" name="target_gross_margin_pct" value="{{ data_get($salesTarget, 'inputs.expected_gross_margin_pct', 25) }}" class="input input-bordered input-sm">
                        </label>
                        <label class="form-control">
                            <span class="label-text text-xs font-semibold">Net Profit %</span>
                            <input type="number" min="0" max="80" step="0.01" name="target_net_margin_pct" value="{{ data_get($salesTarget, 'inputs.desired_net_margin_pct', 10) }}" class="input input-bordered input-sm">
                        </label>
                        <label class="form-control">
                            <span class="label-text text-xs font-semibold">Growth %</span>
                            <input type="number" min="0" max="200" step="0.01" name="target_growth_pct" value="{{ data_get($salesTarget, 'inputs.growth_pct', 0) }}" class="input input-bordered input-sm">
                        </label>
                        <label class="form-control">
                            <span class="label-text text-xs font-semibold">Expense Allocation %</span>
                            <input type="number" min="0" max="100" step="0.01" name="target_expense_allocation_pct" value="{{ data_get($salesTarget, 'inputs.expense_allocation_pct', 100) }}" class="input input-bordered input-sm">
                        </label>
                    </div>
                    <div class="mt-4 flex justify-end">
                        <x-tallui-button type="submit" label="Recalculate" icon="o-calculator" class="btn-primary btn-sm" />
                    </div>
                </x-tallui-card>
            </form>

            <div class="stats shadow w-full mb-6">
                <x-tallui-stat
                    title="Target Revenue"
                    :value="number_format((float) data_get($salesTarget, 'target.revenue', 0), 2)"
                    :desc="data_get($salesTarget, 'window.target_days', 30) . ' day sales team target'"
                    icon="o-trophy"
                />
                <x-tallui-stat
                    title="Daily Target"
                    :value="number_format((float) data_get($salesTarget, 'target.daily_revenue', 0), 2)"
                    desc="Required average sales per day"
                    icon="o-calendar-days"
                />
                <x-tallui-stat
                    title="Target Net Profit"
                    :value="number_format((float) data_get($salesTarget, 'target.net_profit', 0), 2)"
                    :desc="'Cost base ' . number_format((float) data_get($salesTarget, 'target.cost_base', 0), 2)"
                    icon="o-banknotes"
                />
            </div>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-3 mb-6">
                <x-tallui-card
                    title="Target Build"
                    subtitle="Cost recovery plus margin and growth assumptions."
                    icon="o-calculator"
                    :shadow="true"
                >
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between"><span class="text-base-content/60">Target Expense</span><strong>{{ number_format((float) data_get($salesTarget, 'target.expense', 0), 2) }}</strong></div>
                        <div class="flex justify-between"><span class="text-base-content/60">Target Payroll</span><strong>{{ number_format((float) data_get($salesTarget, 'target.payroll', 0), 2) }}</strong></div>
                        <div class="flex justify-between"><span class="text-base-content/60">Cost Base</span><strong>{{ number_format((float) data_get($salesTarget, 'target.cost_base', 0), 2) }}</strong></div>
                        <div class="flex justify-between"><span class="text-base-content/60">Contribution Rate</span><strong>{{ number_format((float) data_get($salesTarget, 'target.contribution_rate_pct', 0), 2) }}%</strong></div>
                        <div class="flex justify-between"><span class="text-base-content/60">Gross Profit Target</span><strong>{{ number_format((float) data_get($salesTarget, 'target.gross_profit', 0), 2) }}</strong></div>
                    </div>
                </x-tallui-card>

                <x-tallui-card
                    title="Recent Baseline"
                    subtitle="Actual sales, COGS, expense, and payroll from the lookback period."
                    icon="o-chart-bar"
                    :shadow="true"
                    class="xl:col-span-2"
                >
                    <div class="grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
                        <div class="rounded-xl border border-base-200 bg-base-100 p-3"><span class="text-xs text-base-content/50">Orders</span><div class="font-semibold">{{ number_format((float) data_get($salesTarget, 'history.orders_count', 0)) }}</div></div>
                        <div class="rounded-xl border border-base-200 bg-base-100 p-3"><span class="text-xs text-base-content/50">Revenue</span><div class="font-semibold">{{ number_format((float) data_get($salesTarget, 'history.revenue', 0), 2) }}</div></div>
                        <div class="rounded-xl border border-base-200 bg-base-100 p-3"><span class="text-xs text-base-content/50">Gross Margin</span><div class="font-semibold">{{ number_format((float) data_get($salesTarget, 'history.gross_margin_pct', 0), 2) }}%</div></div>
                        <div class="rounded-xl border border-base-200 bg-base-100 p-3"><span class="text-xs text-base-content/50">Daily Revenue</span><div class="font-semibold">{{ number_format((float) data_get($salesTarget, 'history.daily_revenue', 0), 2) }}</div></div>
                        <div class="rounded-xl border border-base-200 bg-base-100 p-3"><span class="text-xs text-base-content/50">Expense</span><div class="font-semibold">{{ number_format((float) data_get($salesTarget, 'history.expense', 0), 2) }}</div></div>
                        <div class="rounded-xl border border-base-200 bg-base-100 p-3"><span class="text-xs text-base-content/50">Allocated Expense</span><div class="font-semibold">{{ number_format((float) data_get($salesTarget, 'history.allocated_expense', 0), 2) }}</div></div>
                        <div class="rounded-xl border border-base-200 bg-base-100 p-3"><span class="text-xs text-base-content/50">Payroll</span><div class="font-semibold">{{ number_format((float) data_get($salesTarget, 'history.payroll', 0), 2) }}</div></div>
                        <div class="rounded-xl border border-base-200 bg-base-100 p-3"><span class="text-xs text-base-content/50">Daily Lift</span><div class="font-semibold {{ (float) data_get($salesTarget, 'target.required_daily_lift_pct', 0) > 0 ? 'text-warning' : 'text-success' }}">{{ data_get($salesTarget, 'target.required_daily_lift_pct') === null ? '—' : number_format((float) data_get($salesTarget, 'target.required_daily_lift_pct', 0), 2) . '%' }}</div></div>
                    </div>
                    <div class="mt-3 text-xs text-base-content/50">
                        Expense allocation auto baseline: {{ number_format((float) data_get($salesTarget, 'inputs.auto_expense_allocation_pct', 100), 2) }}%.
                        Accounting: {{ data_get($salesTarget, 'availability.accounting_expenses') ? 'available' : 'not available' }}.
                        Payroll: {{ data_get($salesTarget, 'availability.payroll') ? 'available' : 'not available' }}.
                    </div>
                </x-tallui-card>
            </div>
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
    <a href="{{ route('inventory.requisitions.create') }}"
       class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-clipboard-document-check class="w-7 h-7 text-warning" />
        <span class="text-sm font-medium">Requisition</span>
    </a>
    <a href="{{ route('inventory.sale-orders.create') }}"
       class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-shopping-cart class="w-7 h-7 text-success" />
        <span class="text-sm font-medium">New Sale</span>
    </a>
    <a href="{{ route('inventory.quotations.create') }}"
       class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-document-duplicate class="w-7 h-7 text-info" />
        <span class="text-sm font-medium">Quotation</span>
    </a>
    <a href="{{ route('inventory.pos.index') }}" target="_blank"
       class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-device-phone-mobile class="w-7 h-7 text-secondary" />
        <span class="text-sm font-medium">POS Terminal</span>
    </a>
    <a href="{{ route('inventory.transfers.index') }}"
       class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-arrows-right-left class="w-7 h-7 text-info" />
        <span class="text-sm font-medium">Transfers</span>
    </a>
    @if ($canViewForecast)
    <a href="{{ route('inventory.reports.index') }}"
       class="flex flex-col items-center gap-2 p-4 rounded-2xl border border-base-200 bg-base-100 hover:bg-base-200 transition cursor-pointer text-center">
        <x-heroicon-o-chart-bar class="w-7 h-7 text-secondary" />
        <span class="text-sm font-medium">Reports</span>
    </a>
    @endif
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

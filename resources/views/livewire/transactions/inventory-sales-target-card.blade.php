<div>
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
</div>

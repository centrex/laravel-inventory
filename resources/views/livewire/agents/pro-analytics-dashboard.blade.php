<div>
<x-tallui-notification />

<x-tallui-page-header
    title="Pro Analytics"
    subtitle="Agent performance, revenue breakdown, and multi-channel trends."
    icon="o-chart-bar-square"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Pro Analytics'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <select wire:model.live="period" class="select select-bordered select-sm">
            <option value="this_month">This Month</option>
            <option value="last_30">Last 30 Days</option>
            <option value="last_90">Last 90 Days</option>
            <option value="this_year">This Year</option>
        </select>
        @can('inventory.agents.view')
            <x-tallui-button label="Agents" icon="o-user-group" :link="route('inventory.agents.index')" class="btn-ghost btn-sm" />
        @endcan
        @can('inventory.agents.manage')
            <x-tallui-button label="New Agent" icon="o-user-plus" :link="route('inventory.agents.create')" class="btn-ghost btn-sm" />
        @endcan
        @can('inventory.agent-orders.create')
            <x-tallui-button label="New Agent Order" icon="o-plus" :link="route('inventory.agent-orders.create')" class="btn-primary btn-sm" />
        @endcan
    </x-slot:actions>
</x-tallui-page-header>

{{-- Quick navigation --}}
<div class="mb-5 flex flex-wrap gap-2">
    @can('inventory.agents.view')
        <a href="{{ route('inventory.agents.index') }}"
           class="btn btn-outline btn-sm gap-2">
            <x-tallui-icon name="o-user-group" size="w-4 h-4" />
            Manage Agents
        </a>
    @endcan
    @can('inventory.agents.manage')
        <a href="{{ route('inventory.agents.create') }}"
           class="btn btn-outline btn-sm gap-2">
            <x-tallui-icon name="o-user-plus" size="w-4 h-4" />
            New Agent
        </a>
    @endcan
    @can('inventory.agent-orders.create')
        <a href="{{ route('inventory.agent-orders.create') }}"
           class="btn btn-outline btn-sm gap-2">
            <x-tallui-icon name="o-shopping-cart" size="w-4 h-4" />
            New Agent Order
        </a>
    @endcan
</div>

{{-- Summary stats --}}
<div class="stats shadow w-full mb-6">
    @php
        $totalOrders    = (int) ($summary->total_orders ?? 0);
        $totalRevenue   = (float) ($summary->total_revenue ?? 0);
        $totalCogs      = (float) ($summary->total_cogs ?? 0);
        $grossProfit    = $totalRevenue - $totalCogs;
        $gpPct          = $totalRevenue > 0 ? round($grossProfit / $totalRevenue * 100, 1) : 0.0;
        $agentOrders    = (int) ($agentSummary->agent_orders ?? 0);
        $agentRevenue   = (float) ($agentSummary->b2c_revenue ?? 0);
        $agentMargin    = (float) ($agentSummary->agent_margin ?? 0);
        $agentMarginPct = $agentRevenue > 0 ? round($agentMargin / $agentRevenue * 100, 1) : 0.0;
        $agentShare     = $totalOrders > 0 ? round($agentOrders / $totalOrders * 100, 1) : 0.0;
    @endphp

    <x-tallui-stat title="Total Orders"  :value="number_format($totalOrders)"    :desc="$periodLabel"                                          icon="o-shopping-bag" />
    <x-tallui-stat title="Total Revenue" :value="number_format($totalRevenue, 2)" :desc="'Gross profit ' . $gpPct . '%'"                        icon="o-banknotes" />
    <x-tallui-stat title="Agent Orders"  :value="number_format($agentOrders)"     :desc="$agentShare . '% of total orders'"                     icon="o-user-group" />
    <x-tallui-stat title="Agent Margin"  :value="number_format($agentMargin, 2)"  :desc="'Avg ' . $agentMarginPct . '% on ' . number_format($agentRevenue, 2) . ' B2C revenue'" icon="o-presentation-chart-line" />
</div>

{{-- Tabs --}}
<x-tallui-tab
    :tabs="[
        ['id' => 'trend',   'label' => 'Revenue Trend',     'icon' => 'o-arrow-trending-up'],
        ['id' => 'agents',  'label' => 'Agent Performance', 'icon' => 'o-user-group'],
        ['id' => 'revenue', 'label' => 'Revenue Breakdown', 'icon' => 'o-chart-pie'],
    ]"
    active="trend"
    variant="bordered"
    size="sm"
    class="mb-6"
>
    {{-- Revenue Trend tab --}}
    <x-slot:trend>
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-3 mb-6">
            <x-tallui-card title="Monthly Order Volume" subtitle="Orders placed (excl. B2B mirror orders)" icon="o-shopping-bag" :shadow="true">
                <div class="space-y-2">
                    @php $maxCnt = max(array_filter($monthlyTrend['order_counts']) ?: [1]); @endphp
                    @foreach ($monthlyTrend['categories'] as $i => $month)
                        @php $cnt = $monthlyTrend['order_counts'][$i] ?? 0; @endphp
                        <div class="flex items-center gap-3">
                            <span class="w-20 text-xs text-base-content/50 shrink-0">{{ $month }}</span>
                            <div class="flex-1 bg-base-200 rounded-full h-2 overflow-hidden">
                                <div class="h-2 rounded-full bg-primary" style="width: {{ $maxCnt > 0 ? round($cnt / $maxCnt * 100) : 0 }}%"></div>
                            </div>
                            <span class="text-xs font-semibold w-8 text-right">{{ $cnt }}</span>
                        </div>
                    @endforeach
                </div>
            </x-tallui-card>

            <x-tallui-card title="Revenue Trend (6 Months)" subtitle="Total vs agent-driven revenue" icon="o-arrow-trending-up" :shadow="true" class="xl:col-span-2">
                @if (!empty($monthlyTrend['categories']))
                    <livewire:tallui-bar-chart :series="$monthlyTrend['series']" :categories="$monthlyTrend['categories']" :height="220" />
                @else
                    <x-tallui-empty-state title="No trend data" description="Sales orders will appear here once recorded." icon="o-chart-bar" size="sm" />
                @endif
            </x-tallui-card>
        </div>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2 mb-6">
            <x-tallui-card title="Revenue by Channel" subtitle="Direct orders vs agent-channelled orders" icon="o-arrows-right-left" :shadow="true">
                @forelse ($revenueByRole as $row)
                    @php
                        $roleLabel = match ($row->order_role) {
                            'agent_b2c' => 'Agent (B2C end-customer)',
                            'direct'    => 'Direct Sale',
                            default     => ucfirst(str_replace('_', ' ', $row->order_role)),
                        };
                        $maxRev = $revenueByRole->max('revenue') ?: 1;
                        $barPct = round((float) $row->revenue / (float) $maxRev * 100);
                    @endphp
                    <div class="mb-3">
                        <div class="flex justify-between text-sm mb-1">
                            <span class="font-medium">{{ $roleLabel }}</span>
                            <span class="text-base-content/60">{{ number_format((int) $row->orders_count) }} orders · {{ number_format((float) $row->revenue, 2) }}</span>
                        </div>
                        <div class="w-full bg-base-200 rounded-full h-2">
                            <div class="h-2 rounded-full bg-primary transition-all duration-500" style="width: {{ $barPct }}%"></div>
                        </div>
                    </div>
                @empty
                    <x-tallui-empty-state title="No data" description="No orders in the selected period." icon="o-inbox" size="sm" />
                @endforelse
            </x-tallui-card>

            <x-tallui-card title="Revenue by Price Tier" subtitle="Sales channel price tier breakdown" icon="o-tag" :shadow="true">
                @forelse ($revenueByTier as $row)
                    @php
                        $maxTierRev = $revenueByTier->max('revenue') ?: 1;
                        $tierBarPct = round((float) $row->revenue / (float) $maxTierRev * 100);
                    @endphp
                    <div class="mb-3">
                        <div class="flex justify-between text-sm mb-1">
                            <span class="font-medium font-mono text-xs uppercase">{{ $row->price_tier_code }}</span>
                            <span class="text-base-content/60">{{ number_format((int) $row->orders_count) }} orders · {{ number_format((float) $row->revenue, 2) }}</span>
                        </div>
                        <div class="w-full bg-base-200 rounded-full h-2">
                            <div class="h-2 rounded-full bg-secondary transition-all duration-500" style="width: {{ $tierBarPct }}%"></div>
                        </div>
                    </div>
                @empty
                    <x-tallui-empty-state title="No data" description="No tier data in the selected period." icon="o-inbox" size="sm" />
                @endforelse
            </x-tallui-card>
        </div>
    </x-slot:trend>

    {{-- Agent Performance tab --}}
    <x-slot:agents>
        <div class="stats shadow w-full mb-6">
            <x-tallui-stat title="Active Agents"    :value="number_format($agentRows->count())"                         desc="Agents with orders in period"            icon="o-user-group" />
            <x-tallui-stat title="Total B2C Revenue" :value="number_format((float) ($agentSummary->b2c_revenue ?? 0), 2)" desc="End-customer invoiced value"             icon="o-banknotes" />
            <x-tallui-stat title="Total B2B Cost"    :value="number_format((float) ($agentSummary->b2b_cost ?? 0), 2)"    desc="Agent invoice cost"                      icon="o-arrow-down-tray" />
            <x-tallui-stat title="Net Agent Margin"  :value="number_format($agentMargin, 2)"                              :desc="$agentMarginPct . '% avg margin rate'"  icon="o-presentation-chart-line" />
        </div>

        <x-tallui-card title="Agent Leaderboard" subtitle="Top agents ranked by margin earned in the selected period." icon="o-trophy" :shadow="true">
            @if ($agentRows->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="table table-sm w-full">
                        <thead>
                            <tr class="text-xs uppercase text-base-content/50">
                                <th>#</th>
                                <th>Agent</th>
                                <th class="text-right">Orders</th>
                                <th class="text-right">B2C Revenue</th>
                                <th class="text-right">B2B Cost</th>
                                <th class="text-right">Margin</th>
                                <th class="text-right">Margin %</th>
                                <th>Bar</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $maxMargin = $agentRows->max('margin') ?: 1; @endphp
                            @foreach ($agentRows as $rank => $agent)
                                <tr class="hover">
                                    <td class="text-base-content/40 font-mono text-xs">{{ $rank + 1 }}</td>
                                    <td>
                                        @if ($agent->agent_id)
                                            @can('inventory.agents.manage')
                                                <a href="{{ route('inventory.agents.edit', $agent->agent_id) }}"
                                                   class="font-medium link link-hover">{{ $agent->agent_name }}</a>
                                            @else
                                                <div class="font-medium">{{ $agent->agent_name }}</div>
                                            @endcan
                                        @else
                                            <div class="font-medium">{{ $agent->agent_name }}</div>
                                        @endif
                                        @if ($agent->agent_code)
                                            <div class="text-xs text-base-content/50">{{ $agent->agent_code }}</div>
                                        @endif
                                    </td>
                                    <td class="text-right">{{ number_format((int) $agent->orders_count) }}</td>
                                    <td class="text-right font-medium">{{ number_format((float) $agent->b2c_revenue, 2) }}</td>
                                    <td class="text-right text-base-content/60">{{ number_format((float) $agent->b2b_cost, 2) }}</td>
                                    <td class="text-right font-semibold {{ (float) $agent->margin >= 0 ? 'text-success' : 'text-error' }}">
                                        {{ number_format((float) $agent->margin, 2) }}
                                    </td>
                                    <td class="text-right">
                                        <x-tallui-badge :type="$agent->margin_pct >= 20 ? 'success' : ($agent->margin_pct >= 10 ? 'warning' : 'error')" size="sm">
                                            {{ $agent->margin_pct }}%
                                        </x-tallui-badge>
                                    </td>
                                    <td class="w-24">
                                        <div class="w-full bg-base-200 rounded-full h-1.5">
                                            <div class="h-1.5 rounded-full bg-success" style="width: {{ $maxMargin > 0 ? round((float) $agent->margin / (float) $maxMargin * 100) : 0 }}%"></div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="font-semibold text-sm border-t border-base-300">
                                <td colspan="2" class="text-base-content/60">Total</td>
                                <td class="text-right">{{ number_format($agentOrders) }}</td>
                                <td class="text-right">{{ number_format($agentRevenue, 2) }}</td>
                                <td class="text-right text-base-content/60">{{ number_format((float) ($agentSummary->b2b_cost ?? 0), 2) }}</td>
                                <td class="text-right text-success">{{ number_format($agentMargin, 2) }}</td>
                                <td class="text-right">{{ number_format($agentMarginPct, 1) }}%</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @else
                <x-tallui-empty-state
                    title="No agent orders"
                    description="No agent-driven orders found in the selected period."
                    icon="o-user-group"
                    size="md"
                >
                    <div class="flex flex-wrap justify-center gap-2">
                        @can('inventory.agents.view')
                            <x-tallui-button label="Manage Agents" icon="o-user-group" :link="route('inventory.agents.index')" class="btn-ghost" />
                        @endcan
                        @can('inventory.agent-orders.create')
                            <x-tallui-button label="Create Agent Order" icon="o-plus" :link="route('inventory.agent-orders.create')" class="btn-primary" />
                        @endcan
                    </div>
                </x-tallui-empty-state>
            @endif
        </x-tallui-card>
    </x-slot:agents>

    {{-- Revenue Breakdown tab --}}
    <x-slot:revenue>
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2 mb-6">
            <x-tallui-card title="By Order Role" subtitle="Revenue split between direct sales and agent channels." icon="o-arrows-right-left" :shadow="true">
                @if ($revenueByRole->isNotEmpty())
                    @php $totalRev = $revenueByRole->sum('revenue') ?: 1; @endphp
                    <div class="overflow-x-auto">
                        <table class="table table-sm w-full">
                            <thead>
                                <tr class="text-xs uppercase text-base-content/50">
                                    <th>Channel</th><th class="text-right">Orders</th><th class="text-right">Revenue</th><th class="text-right">Share</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($revenueByRole as $row)
                                    @php
                                        $roleLabel = match ($row->order_role) {
                                            'agent_b2c' => 'Agent — B2C',
                                            'direct'    => 'Direct Sale',
                                            default     => ucfirst(str_replace('_', ' ', $row->order_role)),
                                        };
                                        $sharePct = round((float) $row->revenue / (float) $totalRev * 100, 1);
                                    @endphp
                                    <tr>
                                        <td class="font-medium">{{ $roleLabel }}</td>
                                        <td class="text-right">{{ number_format((int) $row->orders_count) }}</td>
                                        <td class="text-right font-semibold">{{ number_format((float) $row->revenue, 2) }}</td>
                                        <td class="text-right"><x-tallui-badge type="neutral" size="sm">{{ $sharePct }}%</x-tallui-badge></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <x-tallui-empty-state title="No data" description="No orders in the selected period." icon="o-inbox" size="sm" />
                @endif
            </x-tallui-card>

            <x-tallui-card title="By Price Tier" subtitle="Revenue split across B2B, B2C, and wholesale tiers." icon="o-tag" :shadow="true">
                @if ($revenueByTier->isNotEmpty())
                    @php $totalTierRev = $revenueByTier->sum('revenue') ?: 1; @endphp
                    <div class="overflow-x-auto">
                        <table class="table table-sm w-full">
                            <thead>
                                <tr class="text-xs uppercase text-base-content/50">
                                    <th>Price Tier</th><th class="text-right">Orders</th><th class="text-right">Revenue</th><th class="text-right">Share</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($revenueByTier as $row)
                                    @php $tierShare = round((float) $row->revenue / (float) $totalTierRev * 100, 1); @endphp
                                    <tr>
                                        <td class="font-medium font-mono text-xs uppercase">{{ $row->price_tier_code }}</td>
                                        <td class="text-right">{{ number_format((int) $row->orders_count) }}</td>
                                        <td class="text-right font-semibold">{{ number_format((float) $row->revenue, 2) }}</td>
                                        <td class="text-right"><x-tallui-badge type="neutral" size="sm">{{ $tierShare }}%</x-tallui-badge></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <x-tallui-empty-state title="No data" description="No tier data in the selected period." icon="o-inbox" size="sm" />
                @endif
            </x-tallui-card>
        </div>

        <x-tallui-card title="Period Summary" :subtitle="$periodLabel" icon="o-document-chart-bar" :shadow="true">
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <div class="rounded-xl border border-base-200 bg-base-100 p-4">
                    <div class="text-xs text-base-content/50 mb-1">Total Orders</div>
                    <div class="text-2xl font-bold">{{ number_format($totalOrders) }}</div>
                </div>
                <div class="rounded-xl border border-base-200 bg-base-100 p-4">
                    <div class="text-xs text-base-content/50 mb-1">Total Revenue</div>
                    <div class="text-2xl font-bold">{{ number_format($totalRevenue, 2) }}</div>
                </div>
                <div class="rounded-xl border border-base-200 bg-base-100 p-4">
                    <div class="text-xs text-base-content/50 mb-1">Gross Profit</div>
                    <div class="text-2xl font-bold {{ $grossProfit >= 0 ? 'text-success' : 'text-error' }}">{{ number_format($grossProfit, 2) }}</div>
                    <div class="text-xs text-base-content/50 mt-1">{{ $gpPct }}% margin</div>
                </div>
                <div class="rounded-xl border border-base-200 bg-base-100 p-4">
                    <div class="text-xs text-base-content/50 mb-1">Agent Margin</div>
                    <div class="text-2xl font-bold text-primary">{{ number_format($agentMargin, 2) }}</div>
                    <div class="text-xs text-base-content/50 mt-1">{{ $agentMarginPct }}% of agent B2C rev</div>
                </div>
            </div>
        </x-tallui-card>
    </x-slot:revenue>
</x-tallui-tab>
</div>

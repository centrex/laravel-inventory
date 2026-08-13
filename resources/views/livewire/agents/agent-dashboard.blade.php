<div>
    {{-- Breadcrumb + header --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="mb-1 flex items-center gap-2 text-sm text-base-content/50">
                @can('inventory.agents.view')
                    <a href="{{ route('inventory.agents.index') }}" class="link link-hover">Agents</a>
                    <span>/</span>
                @endcan
                <span>{{ $agent->name }}</span>
            </div>
            <h1 class="text-2xl font-bold">{{ $agent->name }}</h1>
            <div class="mt-1 flex flex-wrap items-center gap-3 text-sm text-base-content/60">
                @if ($agent->code)
                    <span class="font-mono">{{ $agent->code }}</span>
                @endif
                @if ($agent->zone || $agent->area)
                    <span>{{ implode(' / ', array_filter([$agent->zone, $agent->area])) }}</span>
                @endif
                <span class="badge badge-outline badge-sm">
                    {{ \Centrex\Inventory\Enums\PriceTierCode::labelFor($agent->price_tier_code) ?? $agent->price_tier_code }}
                </span>
                <span class="font-mono text-xs">{{ $agent->commission_rate_pct }}% commission</span>
                @if ($agent->is_active)
                    <span class="badge badge-success badge-sm">Active</span>
                @else
                    <span class="badge badge-ghost badge-sm">Inactive</span>
                @endif
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @can('inventory.customers.view')
                <a href="{{ route('inventory.agents.customers', $agent->id) }}" class="btn btn-ghost btn-sm">Customers</a>
            @endcan
            @can('inventory.invoices.view')
                <a href="{{ route('inventory.agents.invoices', $agent->id) }}" class="btn btn-ghost btn-sm">Invoices</a>
            @endcan
            @can('inventory.agent-orders.create')
                <a href="{{ route('inventory.agent-orders.create') }}" class="btn btn-outline btn-sm">+ New Order</a>
            @endcan
            @can('inventory.agents.manage')
                <a href="{{ route('inventory.agents.edit', $agent->id) }}" class="btn btn-primary btn-sm">Edit Agent</a>
            @endcan
        </div>
    </div>

    {{-- Stats --}}
    <div class="stats shadow w-full mb-6">
        <div class="stat">
            <div class="stat-title">Linked Customers</div>
            <div class="stat-value text-2xl">{{ number_format($agent->customers_count) }}</div>
            <div class="stat-desc">
                @can('inventory.customers.view')
                    <a href="{{ route('inventory.agents.customers', $agent->id) }}" class="link link-hover">View all</a>
                @endcan
            </div>
        </div>

        @if ($orderStats)
            <div class="stat">
                <div class="stat-title">Total Orders</div>
                <div class="stat-value text-2xl">{{ number_format((int) $orderStats->orders_count) }}</div>
                <div class="stat-desc">Active B2C orders</div>
            </div>
            <div class="stat">
                <div class="stat-title">B2C Revenue</div>
                <div class="stat-value text-2xl">{{ number_format((float) $orderStats->b2c_revenue, 0) }}</div>
                <div class="stat-desc">Customer-facing total</div>
            </div>
            <div class="stat">
                <div class="stat-title">Agent Margin</div>
                <div class="stat-value text-2xl {{ (float) $orderStats->margin > 0 ? 'text-success' : 'text-warning' }}">
                    {{ number_format((float) $orderStats->margin, 0) }}
                </div>
                <div class="stat-desc">{{ $marginPct }}% of B2C revenue</div>
            </div>
        @else
            <div class="stat">
                <div class="stat-title">Orders</div>
                <div class="stat-value text-2xl text-base-content/30">—</div>
                <div class="stat-desc">No billing customer linked</div>
            </div>
        @endif
    </div>

    @if (! $agent->customer_id)
        <div class="alert alert-warning mb-6">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span>
                No billing customer linked to this agent.
                @can('inventory.agents.manage')
                    <a href="{{ route('inventory.agents.edit', $agent->id) }}" class="link font-medium">Set a billing customer</a>
                @endcan
                to enable B2B order creation and agent invoicing.
            </span>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 md:grid-cols-3">

        {{-- Agent details card --}}
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <h2 class="card-title text-base">Agent Details</h2>
                <dl class="space-y-2 text-sm">
                    @if ($agent->email)
                        <div class="flex justify-between">
                            <dt class="text-base-content/60">Email</dt>
                            <dd>{{ $agent->email }}</dd>
                        </div>
                    @endif
                    @if ($agent->phone)
                        <div class="flex justify-between">
                            <dt class="text-base-content/60">Phone</dt>
                            <dd>{{ $agent->phone }}</dd>
                        </div>
                    @endif
                    @if ($agent->zone)
                        <div class="flex justify-between">
                            <dt class="text-base-content/60">Zone</dt>
                            <dd>{{ $agent->zone }}</dd>
                        </div>
                    @endif
                    @if ($agent->area)
                        <div class="flex justify-between">
                            <dt class="text-base-content/60">Area</dt>
                            <dd>{{ $agent->area }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between">
                        <dt class="text-base-content/60">Price Tier</dt>
                        <dd>
                            <span class="badge badge-outline badge-sm">
                                {{ \Centrex\Inventory\Enums\PriceTierCode::labelFor($agent->price_tier_code) ?? $agent->price_tier_code }}
                            </span>
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-base-content/60">Commission</dt>
                        <dd class="font-mono">{{ $agent->commission_rate_pct }}%</dd>
                    </div>
                    @if ($agent->customer)
                        <div class="flex justify-between">
                            <dt class="text-base-content/60">Billing Customer</dt>
                            <dd class="font-medium">{{ $agent->customer->name }}</dd>
                        </div>
                    @endif
                    @if ($agent->notes)
                        <div class="pt-2 border-t border-base-200">
                            <dt class="text-base-content/60 mb-1">Notes</dt>
                            <dd class="text-xs text-base-content/70">{{ $agent->notes }}</dd>
                        </div>
                    @endif
                </dl>
                <div class="card-actions mt-4">
                    @can('inventory.agents.manage')
                        <a href="{{ route('inventory.agents.edit', $agent->id) }}"
                           class="btn btn-ghost btn-sm w-full">Edit Details</a>
                    @endcan
                </div>
            </div>
        </div>

        {{-- Recent orders --}}
        <div class="card bg-base-100 shadow-sm md:col-span-2">
            <div class="card-body p-0">
                <div class="flex items-center justify-between px-4 pt-4 pb-2">
                    <h2 class="card-title text-base">Recent Orders</h2>
                    @can('inventory.agent-orders.create')
                        <a href="{{ route('inventory.agent-orders.create') }}"
                           class="btn btn-primary btn-xs">+ New Order</a>
                    @endcan
                </div>

                @if ($recentOrders->isEmpty())
                    <div class="py-12 text-center text-sm text-base-content/40">
                        @if (! $agent->customer_id)
                            Link a billing customer to start creating agent orders.
                        @else
                            No orders yet.
                            @can('inventory.agent-orders.create')
                                <a href="{{ route('inventory.agent-orders.create') }}" class="link">Create the first one</a>.
                            @endcan
                        @endif
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="table table-zebra table-sm w-full">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Customer</th>
                                    <th>Date</th>
                                    <th class="text-right">B2C Total</th>
                                    <th class="text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recentOrders as $order)
                                    @php
                                        $statusLabel = match($order->status?->value ?? '') {
                                            'draft'       => ['Draft',       'badge-ghost'],
                                            'confirmed'   => ['Confirmed',   'badge-info'],
                                            'processing'  => ['Processing',  'badge-warning'],
                                            'partial'     => ['Partial',     'badge-warning'],
                                            'fulfilled'   => ['Fulfilled',   'badge-success'],
                                            'cancelled'   => ['Cancelled',   'badge-ghost opacity-50'],
                                            'returned'    => ['Returned',    'badge-error'],
                                            default       => [ucfirst((string) ($order->status?->value ?? '')), 'badge-neutral'],
                                        };
                                    @endphp
                                    <tr>
                                        <td class="font-mono text-sm font-medium">{{ $order->so_number }}</td>
                                        <td class="text-sm">{{ $order->customer?->name ?? '—' }}</td>
                                        <td class="text-sm text-base-content/60">
                                            {{ $order->ordered_at ? \Illuminate\Support\Carbon::parse($order->ordered_at)->format('d M Y') : '—' }}
                                        </td>
                                        <td class="text-right font-mono text-sm">{{ number_format((float) $order->total_amount, 2) }}</td>
                                        <td class="text-center">
                                            <span class="badge badge-sm {{ $statusLabel[1] }}">{{ $statusLabel[0] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

    </div>
</div>

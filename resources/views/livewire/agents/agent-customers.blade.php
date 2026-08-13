<div>
    {{-- Header --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="mb-1 flex items-center gap-2 text-sm text-base-content/50">
                <a href="{{ route('inventory.agents.index') }}" class="link link-hover">Agents</a>
                <span>/</span>
                <a href="{{ route('inventory.agents.edit', $agent->id) }}" class="link link-hover">{{ $agent->name }}</a>
                <span>/</span>
                <span>Customers</span>
            </div>
            <h1 class="text-2xl font-bold">{{ $agent->name }} — Customers</h1>
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
                @if (! $agent->is_active)
                    <span class="badge badge-ghost badge-sm">Inactive</span>
                @endif
            </div>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('inventory.agents.edit', $agent->id) }}#customers"
               class="btn btn-ghost btn-sm">Link Existing</a>
            <a href="{{ route('inventory.agents.customers.create', $agent->id) }}"
               class="btn btn-primary btn-sm">+ New Customer</a>
        </div>
    </div>

    {{-- Flash messages --}}
    @if ($successMessage)
        <div class="alert alert-success mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0 stroke-current" fill="none" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span>{{ $successMessage }}</span>
        </div>
    @endif

    @if ($errorMessage)
        <div class="alert alert-error mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0 stroke-current" fill="none" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span>{{ $errorMessage }}</span>
        </div>
    @endif

    {{-- Agent billing customer info bar --}}
    @if ($agent->customer)
        <div class="mb-4 flex items-center gap-3 rounded-xl border border-info/30 bg-info/5 px-4 py-3 text-sm">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-info" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span class="text-base-content/70">
                B2B billing customer:
                <strong>{{ $agent->customer->name }}</strong>
                @if ($agent->customer->code) <span class="font-mono text-xs">({{ $agent->customer->code }})</span> @endif
                — agent invoices are posted against this record.
            </span>
        </div>
    @endif

    {{-- Search + count --}}
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <input type="text" wire:model.live.debounce.300ms="search"
               placeholder="Search name, code, email, phone…"
               class="input input-bordered input-sm w-full max-w-xs" />
        <span class="text-sm text-base-content/50">
            {{ $customers->count() }} customer{{ $customers->count() === 1 ? '' : 's' }}
        </span>
    </div>

    {{-- Customers table --}}
    <div class="card bg-base-100 shadow-sm">
        <div class="card-body p-0">
            <div class="overflow-x-auto">
                <table class="table table-zebra w-full">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Contact</th>
                            <th>Zone / Area</th>
                            <th>Price Tier</th>
                            <th class="text-right">Credit Limit</th>
                            <th>Territory</th>
                            <th>Assigned</th>
                            <th class="text-center">Primary</th>
                            <th class="text-center">Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($customers as $customer)
                            <tr>
                                <td>
                                    <div class="font-medium">{{ $customer->name }}</div>
                                    @if ($customer->code)
                                        <div class="font-mono text-xs text-base-content/50">{{ $customer->code }}</div>
                                    @endif
                                    @if ($customer->organization_name)
                                        <div class="text-xs text-base-content/50">{{ $customer->organization_name }}</div>
                                    @endif
                                </td>
                                <td class="text-sm">
                                    @if ($customer->email)
                                        <div>{{ $customer->email }}</div>
                                    @endif
                                    @if ($customer->phone)
                                        <div class="text-base-content/60">{{ $customer->phone }}</div>
                                    @endif
                                    @if (! $customer->email && ! $customer->phone)
                                        <span class="text-base-content/30">—</span>
                                    @endif
                                </td>
                                <td class="text-sm">
                                    {{ implode(' / ', array_filter([$customer->zone, $customer->area])) ?: '—' }}
                                </td>
                                <td>
                                    <span class="badge badge-outline badge-sm">
                                        {{ \Centrex\Inventory\Enums\PriceTierCode::labelFor($customer->price_tier_code) ?? $customer->price_tier_code ?? '—' }}
                                    </span>
                                </td>
                                <td class="text-right font-mono text-sm">
                                    {{ $customer->currency ?? '' }} {{ number_format((float) $customer->credit_limit_amount, 2) }}
                                </td>
                                <td class="text-sm">{{ $customer->pivot->territory ?? '—' }}</td>
                                <td class="text-sm text-base-content/60">
                                    {{ $customer->pivot->assigned_at
                                        ? \Illuminate\Support\Carbon::parse($customer->pivot->assigned_at)->format('d M Y')
                                        : '—' }}
                                </td>
                                <td class="text-center">
                                    @if ($customer->pivot->is_primary)
                                        <span class="badge badge-primary badge-sm">Primary</span>
                                    @else
                                        <span class="text-base-content/20">—</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if ($customer->is_active)
                                        <span class="badge badge-success badge-sm">Active</span>
                                    @else
                                        <span class="badge badge-ghost badge-sm">Inactive</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('inventory.agent-orders.create', ['agentId' => $agent->id]) }}"
                                           class="btn btn-ghost btn-xs" title="New order for this customer">Order</a>
                                        <button type="button"
                                                class="btn btn-ghost btn-xs text-error"
                                                wire:click="detach({{ $customer->id }})"
                                                wire:confirm="Remove {{ $customer->name }} from this agent?">
                                            Remove
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="py-12 text-center text-base-content/40">
                                    @if ($search)
                                        No customers match "{{ $search }}".
                                    @else
                                        No customers linked yet.
                                        <a href="{{ route('inventory.agents.customers.create', $agent->id) }}" class="link">Create one</a>
                                        or
                                        <a href="{{ route('inventory.agents.edit', $agent->id) }}#customers" class="link">link an existing customer</a>.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

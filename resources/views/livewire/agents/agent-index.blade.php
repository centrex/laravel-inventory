<div>
    {{-- Header --}}
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Sales Agents</h1>
            <p class="mt-1 text-sm text-base-content/60">Manage agents and their linked customers.</p>
        </div>
        <a href="{{ route('inventory.agents.create') }}" class="btn btn-primary btn-sm">
            + New Agent
        </a>
    </div>

    {{-- Search --}}
    <div class="mb-4">
        <input type="text" wire:model.live.debounce.300ms="search"
               placeholder="Search name, code, email, zone…"
               class="input input-bordered w-full max-w-sm" />
    </div>

    {{-- Delete confirm modal --}}
    @if ($confirmDeleteId)
        <div class="modal modal-open">
            <div class="modal-box">
                <h3 class="text-lg font-bold">Delete Agent</h3>
                <p class="py-4">Are you sure? This will soft-delete the agent. Existing orders will not be affected.</p>
                <div class="modal-action">
                    <button class="btn btn-ghost" wire:click="cancelDelete">Cancel</button>
                    <button class="btn btn-error" wire:click="delete">Delete</button>
                </div>
            </div>
            <div class="modal-backdrop" wire:click="cancelDelete"></div>
        </div>
    @endif

    {{-- Table --}}
    <div class="card bg-base-100 shadow-sm">
        <div class="card-body p-0">
            <div class="overflow-x-auto">
                <table class="table table-zebra w-full">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Zone / Area</th>
                            <th>Price Tier</th>
                            <th class="text-right">Commission</th>
                            <th class="text-center">Customers</th>
                            <th class="text-center">Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($agents as $agent)
                            <tr>
                                <td class="font-mono text-sm text-base-content/60">{{ $agent->code ?? '—' }}</td>
                                <td>
                                    <a href="{{ route('inventory.agents.show', $agent->id) }}"
                                       class="font-medium hover:underline">{{ $agent->name }}</a>
                                    @if ($agent->email)
                                        <div class="text-xs text-base-content/50">{{ $agent->email }}</div>
                                    @endif
                                </td>
                                <td class="text-sm">
                                    {{ implode(' / ', array_filter([$agent->zone, $agent->area])) ?: '—' }}
                                </td>
                                <td>
                                    <span class="badge badge-outline badge-sm">
                                        {{ \Centrex\Inventory\Enums\PriceTierCode::labelFor($agent->price_tier_code) ?? $agent->price_tier_code }}
                                    </span>
                                </td>
                                <td class="text-right font-mono text-sm">{{ $agent->commission_rate_pct }}%</td>
                                <td class="text-center">
                                    <a href="{{ route('inventory.agents.customers', $agent->id) }}"
                                       class="badge badge-neutral badge-sm hover:badge-primary">
                                        {{ $agent->customers_count }}
                                    </a>
                                </td>
                                <td class="text-center">
                                    @if ($agent->is_active)
                                        <span class="badge badge-success badge-sm">Active</span>
                                    @else
                                        <span class="badge badge-ghost badge-sm">Inactive</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('inventory.agents.edit', $agent->id) }}"
                                           class="btn btn-ghost btn-xs">Edit</a>
                                        <button class="btn btn-ghost btn-xs text-error"
                                                wire:click="confirmDelete({{ $agent->id }})">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-12 text-center text-base-content/40">
                                    @if ($search)
                                        No agents match "{{ $search }}".
                                    @else
                                        No agents yet. <a href="{{ route('inventory.agents.create') }}" class="link">Create one</a>.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($agents->hasPages())
                <div class="p-4">
                    {{ $agents->links() }}
                </div>
            @endif
        </div>
    </div>
</div>

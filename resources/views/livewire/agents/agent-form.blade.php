<div>
    {{-- Header --}}
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">{{ $isEdit ? 'Edit Agent' : 'New Agent' }}</h1>
            <p class="mt-1 text-sm text-base-content/60">
                {{ $isEdit ? 'Update agent details and manage linked customers.' : 'Fill in the details then save to link customers.' }}
            </p>
        </div>
        <a href="{{ route('inventory.agents.index') }}" class="btn btn-ghost btn-sm">← Back to Agents</a>
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

    <div class="space-y-6">

        {{-- ── Section 1: Agent Details ── --}}
        <form wire:submit="save">
            <div class="card bg-base-100 shadow-sm">
                <div class="card-body">
                    <h2 class="card-title text-base">Agent Details</h2>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">

                        {{-- Name --}}
                        <div class="form-control md:col-span-2">
                            <label class="label"><span class="label-text font-medium">Name <span class="text-error">*</span></span></label>
                            <input type="text" wire:model="name" placeholder="Full agent name"
                                   class="input input-bordered w-full @error('name') input-error @enderror" />
                            @error('name') <span class="label-text-alt mt-1 text-error">{{ $message }}</span> @enderror
                        </div>

                        {{-- Code --}}
                        <div class="form-control">
                            <label class="label"><span class="label-text font-medium">Code</span></label>
                            <input type="text" wire:model="code" placeholder="e.g. AGT-001"
                                   class="input input-bordered w-full @error('code') input-error @enderror" />
                            @error('code') <span class="label-text-alt mt-1 text-error">{{ $message }}</span> @enderror
                        </div>

                        {{-- Email --}}
                        <div class="form-control">
                            <label class="label"><span class="label-text font-medium">Email</span></label>
                            <input type="email" wire:model="email" placeholder="agent@example.com"
                                   class="input input-bordered w-full @error('email') input-error @enderror" />
                            @error('email') <span class="label-text-alt mt-1 text-error">{{ $message }}</span> @enderror
                        </div>

                        {{-- Phone --}}
                        <div class="form-control">
                            <label class="label"><span class="label-text font-medium">Phone</span></label>
                            <input type="text" wire:model="phone" placeholder="+880…"
                                   class="input input-bordered w-full @error('phone') input-error @enderror" />
                            @error('phone') <span class="label-text-alt mt-1 text-error">{{ $message }}</span> @enderror
                        </div>

                        {{-- Zone --}}
                        <div class="form-control">
                            <label class="label"><span class="label-text font-medium">Zone</span></label>
                            <input type="text" wire:model="zone" placeholder="e.g. Dhaka"
                                   class="input input-bordered w-full @error('zone') input-error @enderror" />
                            @error('zone') <span class="label-text-alt mt-1 text-error">{{ $message }}</span> @enderror
                        </div>

                        {{-- Area --}}
                        <div class="form-control">
                            <label class="label"><span class="label-text font-medium">Area</span></label>
                            <input type="text" wire:model="area" placeholder="e.g. Gulshan"
                                   class="input input-bordered w-full @error('area') input-error @enderror" />
                            @error('area') <span class="label-text-alt mt-1 text-error">{{ $message }}</span> @enderror
                        </div>

                        {{-- Price Tier --}}
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text font-medium">Price Tier <span class="text-error">*</span></span>
                                <span class="label-text-alt text-base-content/50">B2B wholesale price for this agent</span>
                            </label>
                            <select wire:model="priceTierCode"
                                    class="select select-bordered w-full @error('priceTierCode') select-error @enderror">
                                @foreach ($tiers as $tier)
                                    <option value="{{ $tier->value }}">{{ $tier->label() }}</option>
                                @endforeach
                            </select>
                            @error('priceTierCode') <span class="label-text-alt mt-1 text-error">{{ $message }}</span> @enderror
                        </div>

                        {{-- Commission --}}
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text font-medium">Commission %</span>
                            </label>
                            <label class="input input-bordered flex items-center gap-1 @error('commissionRatePct') input-error @enderror">
                                <input type="number" step="0.01" min="0" max="100"
                                       wire:model="commissionRatePct" class="grow" />
                                <span class="text-base-content/50">%</span>
                            </label>
                            @error('commissionRatePct') <span class="label-text-alt mt-1 text-error">{{ $message }}</span> @enderror
                        </div>

                        {{-- Billing Customer (agent's own inv_customers record for B2B invoicing) --}}
                        <div class="form-control md:col-span-2">
                            <label class="label">
                                <span class="label-text font-medium">Billing Customer (B2B)</span>
                                <span class="label-text-alt text-base-content/50">The agent's own customer record used on B2B sale orders</span>
                            </label>
                            <select wire:model="billingCustomerId"
                                    class="select select-bordered w-full @error('billingCustomerId') select-error @enderror">
                                <option value="">— None —</option>
                                @foreach ($allCustomers as $c)
                                    <option value="{{ $c->id }}">{{ $c->name }}{{ $c->code ? " ({$c->code})" : '' }}</option>
                                @endforeach
                            </select>
                            @error('billingCustomerId') <span class="label-text-alt mt-1 text-error">{{ $message }}</span> @enderror
                        </div>

                        {{-- Status --}}
                        <div class="form-control">
                            <label class="label"><span class="label-text font-medium">Status</span></label>
                            <label class="flex cursor-pointer items-center gap-3">
                                <input type="checkbox" wire:model="isActive" class="toggle toggle-success" />
                                <span class="text-sm">{{ $isActive ? 'Active' : 'Inactive' }}</span>
                            </label>
                        </div>

                    </div>

                    {{-- Notes --}}
                    <div class="form-control mt-2">
                        <label class="label"><span class="label-text font-medium">Notes</span></label>
                        <textarea wire:model="notes" rows="2" placeholder="Internal notes…"
                                  class="textarea textarea-bordered w-full"></textarea>
                    </div>

                    {{-- Save button --}}
                    <div class="mt-4 flex justify-end gap-3">
                        <a href="{{ route('inventory.agents.index') }}" class="btn btn-ghost">Cancel</a>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
                            <span wire:loading.remove>{{ $isEdit ? 'Save Changes' : 'Create Agent' }}</span>
                            <span wire:loading class="loading loading-spinner loading-sm"></span>
                            <span wire:loading>Saving…</span>
                        </button>
                    </div>
                </div>
            </div>
        </form>

        {{-- ── Section 2: Linked Customers (only shown after agent is saved) ── --}}
        @if ($agentId)
            <div id="customers" class="card bg-base-100 shadow-sm">
                <div class="card-body">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="card-title text-base">Linked Customers</h2>
                        <div class="flex gap-2">
                            <a href="{{ route('inventory.agents.customers.create', $agentId) }}"
                               class="btn btn-ghost btn-sm">+ New Customer</a>
                            <a href="{{ route('inventory.agents.customers', $agentId) }}"
                               class="btn btn-outline btn-sm">View All</a>
                        </div>
                    </div>
                    <p class="mb-4 text-sm text-base-content/60">
                        These end-customers are serviced by this agent. Their orders will appear as B2C orders
                        paired with a B2B agent order.
                    </p>

                    {{-- Attach form --}}
                    <div class="rounded-xl border border-base-200 bg-base-200/40 p-4">
                        <h3 class="mb-3 text-sm font-semibold">Connect an Existing Customer</h3>
                        <div class="flex flex-wrap items-end gap-3">

                            {{-- Customer search/select --}}
                            <div class="form-control flex-1" style="min-width: 220px"
                                 x-data="{
                                     customers: @js($availableCustomers->map(fn($c) => ['id' => $c->id, 'name' => $c->name, 'code' => $c->code])->values()->all()),
                                     search: '',
                                     open: false,
                                     rect: {},
                                     get filtered() {
                                         const q = this.search.toLowerCase().trim();
                                         if (!q) return this.customers.slice(0, 25);
                                         return this.customers.filter(c =>
                                             c.name.toLowerCase().includes(q) ||
                                             (c.code && c.code.toLowerCase().includes(q))
                                         ).slice(0, 30);
                                     },
                                     updateRect() { const el = this.$refs.anchor; if (el) this.rect = el.getBoundingClientRect(); },
                                     select(c) {
                                         this.search = c.name + (c.code ? ' (' + c.code + ')' : '');
                                         this.open = false;
                                         $wire.set('attachCustomerId', c.id);
                                     }
                                 }"
                                 x-init="
                                     const handler = e => { if (!$refs.anchor?.contains(e.target)) open = false; };
                                     document.addEventListener('click', handler);
                                     $cleanup(() => document.removeEventListener('click', handler));
                                 "
                            >
                                <label class="label"><span class="label-text text-sm">Customer <span class="text-error">*</span></span></label>
                                <div x-ref="anchor" class="relative">
                                    <input type="text"
                                           x-model="search"
                                           x-on:focus="updateRect(); open = true"
                                           x-on:input="open = true"
                                           placeholder="Search customer name or code…"
                                           autocomplete="off"
                                           class="input input-bordered input-sm w-full @error('attachCustomerId') input-error @enderror" />
                                    <template x-teleport="body">
                                        <div x-show="open && filtered.length > 0"
                                             x-on:click.stop
                                             x-transition:enter="transition ease-out duration-100"
                                             x-transition:enter-start="opacity-0 scale-95"
                                             x-transition:enter-end="opacity-100 scale-100"
                                             :style="{
                                                 position: 'fixed',
                                                 top: (rect.bottom + 4) + 'px',
                                                 left: rect.left + 'px',
                                                 width: Math.max(260, rect.width) + 'px',
                                                 zIndex: 9999,
                                             }"
                                             class="max-h-56 overflow-y-auto rounded-xl border border-base-200 bg-base-100 shadow-2xl">
                                            <template x-for="c in filtered" :key="c.id">
                                                <div x-on:click="select(c)"
                                                     class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm hover:bg-base-200">
                                                    <span class="font-medium" x-text="c.name"></span>
                                                    <span class="text-xs text-base-content/50" x-show="c.code" x-text="'(' + c.code + ')'"></span>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                                @error('attachCustomerId') <span class="label-text-alt mt-1 text-error text-xs">{{ $message }}</span> @enderror
                            </div>

                            {{-- Territory --}}
                            <div class="form-control w-40">
                                <label class="label"><span class="label-text text-sm">Territory</span></label>
                                <input type="text" wire:model="attachTerritory" placeholder="e.g. North"
                                       class="input input-bordered input-sm w-full" />
                            </div>

                            {{-- Primary toggle --}}
                            <div class="form-control">
                                <label class="label"><span class="label-text text-sm">Primary?</span></label>
                                <input type="checkbox" wire:model="attachIsPrimary" class="toggle toggle-sm toggle-primary mt-1" />
                            </div>

                            {{-- Attach button --}}
                            <div class="form-control">
                                <label class="label"><span class="label-text text-sm">&nbsp;</span></label>
                                <button type="button" class="btn btn-primary btn-sm"
                                        wire:click="attachCustomer"
                                        wire:loading.attr="disabled">
                                    <span wire:loading.remove wire:target="attachCustomer">Link Customer</span>
                                    <span wire:loading wire:target="attachCustomer" class="loading loading-spinner loading-xs"></span>
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- Linked customers list --}}
                    @if ($linkedCustomers->isNotEmpty())
                        <div class="mt-4 overflow-x-auto">
                            <table class="table table-zebra w-full">
                                <thead>
                                    <tr>
                                        <th>Customer</th>
                                        <th>Code</th>
                                        <th>Territory</th>
                                        <th>Assigned</th>
                                        <th class="text-center">Primary</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($linkedCustomers as $customer)
                                        <tr>
                                            <td class="font-medium">{{ $customer->name }}</td>
                                            <td class="font-mono text-sm text-base-content/60">{{ $customer->code ?? '—' }}</td>
                                            <td class="text-sm">{{ $customer->pivot->territory ?? '—' }}</td>
                                            <td class="text-sm text-base-content/60">
                                                {{ $customer->pivot->assigned_at ? \Illuminate\Support\Carbon::parse($customer->pivot->assigned_at)->format('d M Y') : '—' }}
                                            </td>
                                            <td class="text-center">
                                                @if ($customer->pivot->is_primary)
                                                    <span class="badge badge-primary badge-sm">Primary</span>
                                                @else
                                                    <span class="text-base-content/30">—</span>
                                                @endif
                                            </td>
                                            <td class="text-right">
                                                <button type="button"
                                                        class="btn btn-ghost btn-xs text-error"
                                                        wire:click="detachCustomer({{ $customer->id }})"
                                                        wire:confirm="Remove this customer from the agent?">
                                                    Remove
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="mt-4 rounded-xl border border-dashed border-base-300 py-10 text-center text-sm text-base-content/40">
                            No customers linked yet. Use the form above to connect one.
                        </div>
                    @endif
                </div>
            </div>
        @else
            <div class="rounded-xl border border-dashed border-base-300 py-8 text-center text-sm text-base-content/40">
                Save the agent first — the customer linking section will appear here.
            </div>
        @endif

    </div>
</div>

<div>
    {{-- Breadcrumb + header --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="mb-1 flex items-center gap-2 text-sm text-base-content/50">
                <a href="{{ route('inventory.agents.index') }}" class="link link-hover">Agents</a>
                <span>/</span>
                <a href="{{ route('inventory.agents.edit', $agent->id) }}" class="link link-hover">{{ $agent->name }}</a>
                <span>/</span>
                <a href="{{ route('inventory.agents.customers', $agent->id) }}" class="link link-hover">Customers</a>
                <span>/</span>
                <span>New</span>
            </div>
            <h1 class="text-2xl font-bold">New Customer for {{ $agent->name }}</h1>
            <p class="mt-1 text-sm text-base-content/60">
                Creates a new customer record and automatically links it to this agent.
            </p>
        </div>
        <a href="{{ route('inventory.agents.customers', $agent->id) }}" class="btn btn-ghost btn-sm">
            ← Back to Customers
        </a>
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

    <form wire:submit="save" class="space-y-6">

        {{-- ── Customer Details ── --}}
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <h2 class="card-title text-base">Customer Details</h2>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">

                    {{-- Name --}}
                    <div class="form-control md:col-span-2">
                        <label class="label"><span class="label-text font-medium">Full Name <span class="text-error">*</span></span></label>
                        <input type="text" wire:model="name" placeholder="Customer full name"
                               class="input input-bordered w-full @error('name') input-error @enderror" autofocus />
                        @error('name') <span class="label-text-alt mt-1 text-error">{{ $message }}</span> @enderror
                    </div>

                    {{-- Code --}}
                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">Code <span class="text-error">*</span></span></label>
                        <input type="text" wire:model="code" placeholder="e.g. C-001"
                               class="input input-bordered w-full @error('code') input-error @enderror" />
                        @error('code') <span class="label-text-alt mt-1 text-error">{{ $message }}</span> @enderror
                    </div>

                    {{-- Organisation --}}
                    <div class="form-control md:col-span-3">
                        <label class="label"><span class="label-text font-medium">Organisation / Business Name</span></label>
                        <input type="text" wire:model="organizationName" placeholder="Company or shop name (optional)"
                               class="input input-bordered w-full @error('organizationName') input-error @enderror" />
                        @error('organizationName') <span class="label-text-alt mt-1 text-error">{{ $message }}</span> @enderror
                    </div>

                    {{-- Email --}}
                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">Email</span></label>
                        <input type="email" wire:model="email" placeholder="customer@example.com"
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

                    {{-- Status --}}
                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">Status</span></label>
                        <label class="flex cursor-pointer items-center gap-3 pt-2">
                            <input type="checkbox" wire:model="isActive" class="toggle toggle-success" />
                            <span class="text-sm">{{ $isActive ? 'Active' : 'Inactive' }}</span>
                        </label>
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
                        <input type="text" wire:model="area" placeholder="e.g. Mirpur"
                               class="input input-bordered w-full @error('area') input-error @enderror" />
                        @error('area') <span class="label-text-alt mt-1 text-error">{{ $message }}</span> @enderror
                    </div>

                    {{-- Currency --}}
                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">Currency <span class="text-error">*</span></span></label>
                        <input type="text" wire:model="currency" placeholder="BDT"
                               class="input input-bordered w-full @error('currency') input-error @enderror" maxlength="10" />
                        @error('currency') <span class="label-text-alt mt-1 text-error">{{ $message }}</span> @enderror
                    </div>

                    {{-- Credit Limit --}}
                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">Credit Limit</span></label>
                        <label class="input input-bordered flex items-center gap-1 @error('creditLimitAmount') input-error @enderror">
                            <span class="text-base-content/50 text-sm">{{ $currency ?: 'BDT' }}</span>
                            <input type="number" step="0.01" min="0" wire:model="creditLimitAmount" class="grow" />
                        </label>
                        @error('creditLimitAmount') <span class="label-text-alt mt-1 text-error">{{ $message }}</span> @enderror
                    </div>

                    {{-- Price Tier --}}
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text font-medium">Price Tier <span class="text-error">*</span></span>
                            <span class="label-text-alt text-base-content/50">Selling price for this customer</span>
                        </label>
                        <select wire:model="priceTierCode"
                                class="select select-bordered w-full @error('priceTierCode') select-error @enderror">
                            @foreach ($tiers as $tier)
                                <option value="{{ $tier->value }}">{{ $tier->label() }}</option>
                            @endforeach
                        </select>
                        @error('priceTierCode') <span class="label-text-alt mt-1 text-error">{{ $message }}</span> @enderror
                    </div>

                </div>
            </div>
        </div>

        {{-- ── Agent Link Details ── --}}
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <h2 class="card-title text-base">Agent Assignment</h2>
                <p class="mb-3 text-sm text-base-content/60">
                    This customer will be linked to <strong>{{ $agent->name }}</strong> automatically.
                    Optionally set territory and primary status.
                </p>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">

                    {{-- Territory --}}
                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">Territory</span></label>
                        <input type="text" wire:model="territory" placeholder="e.g. North Dhaka"
                               class="input input-bordered w-full @error('territory') input-error @enderror" />
                        @error('territory') <span class="label-text-alt mt-1 text-error">{{ $message }}</span> @enderror
                    </div>

                    {{-- Primary --}}
                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">Primary Customer?</span></label>
                        <label class="flex cursor-pointer items-center gap-3 pt-2">
                            <input type="checkbox" wire:model="isPrimary" class="toggle toggle-primary toggle-sm" />
                            <span class="text-sm text-base-content/70">Mark as primary for this agent</span>
                        </label>
                    </div>

                </div>
            </div>
        </div>

        {{-- Actions --}}
        <div class="flex justify-end gap-3">
            <a href="{{ route('inventory.agents.customers', $agent->id) }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
                <span wire:loading.remove>Create Customer</span>
                <span wire:loading class="loading loading-spinner loading-sm"></span>
                <span wire:loading>Creating…</span>
            </button>
        </div>

    </form>
</div>

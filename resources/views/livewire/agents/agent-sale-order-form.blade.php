<div>
    {{-- Page header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Create Agent Sale Order</h1>
            <p class="mt-1 text-sm text-base-content/60">
                Creates a paired B2C customer order and B2B agent cost order simultaneously.
            </p>
        </div>
    </div>

    {{-- Flash messages --}}
    @if ($successMessage)
        <div class="alert alert-success mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0 stroke-current" fill="none" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>{{ $successMessage }}</span>
        </div>
    @endif

    @if ($errorMessage)
        <div class="alert alert-error mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0 stroke-current" fill="none" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>{{ $errorMessage }}</span>
        </div>
    @endif

    <form wire:submit="submit" class="space-y-6">

        {{-- Order header --}}
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <h2 class="card-title text-base">Order Details</h2>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    {{-- Warehouse --}}
                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">Warehouse <span class="text-error">*</span></span></label>
                        <select class="select select-bordered w-full @error('warehouseId') select-error @enderror"
                                wire:model.live="warehouseId">
                            <option value="">Select warehouse…</option>
                            @foreach ($warehouses as $wh)
                                <option value="{{ $wh->id }}">{{ $wh->name }}</option>
                            @endforeach
                        </select>
                        @error('warehouseId') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                    </div>

                    {{-- Agent (B2B) — must be selected first so customers can be filtered --}}
                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">Sales Agent (B2B) <span class="text-error">*</span></span></label>
                        <select class="select select-bordered w-full @error('agentId') select-error @enderror"
                                wire:model.live="agentId">
                            <option value="">Select agent…</option>
                            @foreach ($agents as $a)
                                <option value="{{ $a->id }}">{{ $a->name }} {{ $a->code ? "({$a->code})" : '' }}</option>
                            @endforeach
                        </select>
                        @error('agentId') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                    </div>

                    {{-- End customer (B2C) — filtered to agent's customers once agent is selected --}}
                    <div class="form-control">
                        <label class="label"><span class="label-text font-medium">Customer (B2C) <span class="text-error">*</span></span></label>
                        <select class="select select-bordered w-full @error('customerId') select-error @enderror"
                                wire:model.live="customerId"
                                @disabled(! $agentId)>
                            <option value="">
                                {{ $agentId ? 'Select customer…' : 'Select agent first…' }}
                            </option>
                            @foreach ($customers as $c)
                                <option value="{{ $c->id }}">{{ $c->name }} {{ $c->code ? "({$c->code})" : '' }}</option>
                            @endforeach
                        </select>
                        @if ($agentId && $customers->isEmpty())
                            <span class="label-text-alt text-warning mt-1">No customers linked to this agent yet.</span>
                        @endif
                        @error('customerId') <span class="label-text-alt text-error mt-1">{{ $message }}</span> @enderror
                    </div>
                </div>

                {{-- Notes --}}
                <div class="form-control mt-2">
                    <label class="label"><span class="label-text font-medium">Notes</span></label>
                    <textarea class="textarea textarea-bordered w-full" rows="2"
                              wire:model="notes" placeholder="Internal notes…"></textarea>
                </div>
            </div>
        </div>

        {{-- Items table --}}
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body p-0">
                <div class="overflow-x-auto">
                    <table class="table table-zebra w-full">
                        <thead>
                            <tr>
                                <th class="w-8">#</th>
                                <th>Product</th>
                                <th class="w-20 text-right">Qty</th>
                                <th class="w-32 text-right">B2C Unit Price</th>
                                <th class="w-32 text-right">B2B Unit Price</th>
                                <th class="w-32 text-right">B2C Total</th>
                                <th class="w-32 text-right">B2B Total</th>
                                <th class="w-10"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($items as $i => $item)
                                <tr wire:key="item-{{ $i }}">
                                    <td class="text-base-content/50 text-sm">{{ $i + 1 }}</td>

                                    {{-- Product selector (searchable combobox) --}}
                                    <td>
                                        <div
                                            wire:key="agent-product-{{ $i }}"
                                            x-data="{
                                                products: @js($productsJson),
                                                search: '',
                                                open: false,
                                                rect: {},
                                                get filtered() {
                                                    const q = this.search.toLowerCase().trim();
                                                    if (!q) return this.products.slice(0, 20);
                                                    return this.products.filter(p =>
                                                        p.name.toLowerCase().includes(q) ||
                                                        (p.sku && p.sku.toLowerCase().includes(q)) ||
                                                        (p.barcode && p.barcode.toLowerCase().includes(q))
                                                    ).slice(0, 30);
                                                },
                                                updateRect() {
                                                    const el = this.$refs.anchor;
                                                    if (el) this.rect = el.getBoundingClientRect();
                                                },
                                                select(p) {
                                                    this.search = p.name + (p.sku ? ' · ' + p.sku : '');
                                                    this.open = false;
                                                    $wire.set('items.{{ $i }}.product_id', p.id);
                                                }
                                            }"
                                            x-init="
                                                const id = {{ (int) ($item['product_id'] ?? 0) }};
                                                if (id) {
                                                    const p = this.products.find(x => x.id === id);
                                                    if (p) search = p.name + (p.sku ? ' · ' + p.sku : '');
                                                }
                                                const handler = (e) => { if (!$refs.anchor?.contains(e.target)) open = false; };
                                                document.addEventListener('click', handler);
                                                $cleanup(() => document.removeEventListener('click', handler));
                                            "
                                        >
                                            <div x-ref="anchor" class="min-w-52">
                                                <input
                                                    type="text"
                                                    x-model="search"
                                                    x-on:focus="updateRect(); open = true"
                                                    x-on:input="open = true"
                                                    placeholder="Search name or SKU…"
                                                    autocomplete="off"
                                                    class="input input-bordered input-sm w-full @error("items.{$i}.product_id") input-error @enderror"
                                                />
                                            </div>
                                            <template x-teleport="body">
                                                <div
                                                    x-show="open && filtered.length > 0"
                                                    x-on:click.stop
                                                    x-transition:enter="transition ease-out duration-100"
                                                    x-transition:enter-start="opacity-0 scale-95"
                                                    x-transition:enter-end="opacity-100 scale-100"
                                                    :style="{
                                                        position: 'fixed',
                                                        top: (rect.bottom + 4) + 'px',
                                                        left: rect.left + 'px',
                                                        width: Math.max(280, rect.width) + 'px',
                                                        zIndex: 9999,
                                                    }"
                                                    class="max-h-56 overflow-y-auto rounded-xl border border-base-200 bg-base-100 shadow-2xl"
                                                >
                                                    <template x-for="p in filtered" :key="p.id">
                                                        <div
                                                            x-on:click="select(p)"
                                                            class="flex cursor-pointer items-center justify-between gap-2 px-3 py-2 text-sm hover:bg-base-200"
                                                        >
                                                            <div class="min-w-0">
                                                                <div class="truncate font-medium" x-text="p.name"></div>
                                                                <div class="text-xs text-base-content/50" x-show="p.sku || p.barcode">
                                                                    <span x-show="p.sku" x-text="p.sku"></span>
                                                                    <span x-show="p.sku && p.barcode"> · </span>
                                                                    <span x-show="p.barcode" x-text="p.barcode"></span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </template>
                                                </div>
                                            </template>
                                            @error("items.{$i}.product_id")
                                                <p class="mt-1 text-xs text-error">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    </td>

                                    {{-- Qty --}}
                                    <td>
                                        <input type="number" step="0.01" min="0.01"
                                               class="input input-bordered input-sm w-20 text-right @error("items.{$i}.qty") input-error @enderror"
                                               wire:model.live="items.{{ $i }}.qty" />
                                        @error("items.{$i}.qty")
                                            <span class="text-error text-xs">{{ $message }}</span>
                                        @enderror
                                    </td>

                                    {{-- B2C price (editable) --}}
                                    <td>
                                        <input type="number" step="0.01" min="0"
                                               class="input input-bordered input-sm w-32 text-right"
                                               wire:model.live="items.{{ $i }}.b2c_price" />
                                    </td>

                                    {{-- B2B price (editable, shown in muted style) --}}
                                    <td>
                                        <input type="number" step="0.01" min="0"
                                               class="input input-bordered input-sm w-32 text-right text-warning"
                                               wire:model.live="items.{{ $i }}.b2b_price" />
                                    </td>

                                    {{-- B2C total --}}
                                    <td class="text-right font-mono text-sm">
                                        {{ number_format((float) ($item['qty'] ?? 0) * (float) ($item['b2c_price'] ?? 0), 2) }}
                                    </td>

                                    {{-- B2B total --}}
                                    <td class="text-right font-mono text-sm text-warning">
                                        {{ number_format((float) ($item['qty'] ?? 0) * (float) ($item['b2b_price'] ?? 0), 2) }}
                                    </td>

                                    {{-- Remove --}}
                                    <td>
                                        @if (count($items) > 1)
                                            <button type="button" class="btn btn-ghost btn-xs text-error"
                                                    wire:click="removeItem({{ $i }})">✕</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-between p-4">
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="addItem">
                        + Add Product
                    </button>
                </div>
            </div>
        </div>

        {{-- Summary panel --}}
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <div class="flex flex-col items-end gap-1 ml-auto">
                    <div class="flex w-72 justify-between text-sm">
                        <span class="text-base-content/70">B2C Total (Customer pays):</span>
                        <span class="font-semibold">{{ $currency }} {{ number_format($b2cTotal, 2) }}</span>
                    </div>
                    <div class="flex w-72 justify-between text-sm text-warning">
                        <span>B2B Total (Agent cost):</span>
                        <span class="font-semibold">{{ $currency }} {{ number_format($b2bTotal, 2) }}</span>
                    </div>
                    <div class="divider my-1 w-72"></div>
                    <div class="flex w-72 justify-between text-sm">
                        <span class="text-base-content/70">Agent Margin:</span>
                        <span class="font-bold {{ $agentMargin >= 0 ? 'text-success' : 'text-error' }}">
                            {{ $currency }} {{ number_format($agentMargin, 2) }}
                            @if ($b2cTotal > 0)
                                ({{ number_format($agentMargin / $b2cTotal * 100, 1) }}%)
                            @endif
                        </span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Actions --}}
        <div class="flex justify-end gap-3">
            <a href="{{ url()->previous() }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
                <span wire:loading.remove>Create Paired Orders</span>
                <span wire:loading class="loading loading-spinner loading-sm"></span>
                <span wire:loading>Creating…</span>
            </button>
        </div>

    </form>
</div>

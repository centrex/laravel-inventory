<div>
<x-tallui-notification />

<x-tallui-page-header
    title="New Shipment"
    subtitle="Prepare inter-warehouse shipments with box-level packing and landed-cost allocation."
    icon="o-paper-airplane"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Shipments', 'href' => route('inventory.shipments.index')],
            ['label' => 'New Shipment'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-badge type="info">Shipments</x-tallui-badge>
    </x-slot:actions>
</x-tallui-page-header>

<form wire:submit="save" class="space-y-4">

    {{-- Header --}}
    <x-tallui-card title="Shipment Details" subtitle="Source, destination, and shipping cost." icon="o-truck" :shadow="true">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <x-tallui-form-group label="From Warehouse *" :error="$errors->first('from_warehouse_id')">
                <select
                    name="from_warehouse_id"
                    wire:model.live="from_warehouse_id"
                    class="select select-bordered select-sm w-full {{ $errors->has('from_warehouse_id') ? 'select-error' : '' }}"
                >
                    <option value="">Select source…</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected($from_warehouse_id == $warehouse->id)>{{ $warehouse->name }}</option>
                    @endforeach
                </select>
            </x-tallui-form-group>

            <x-tallui-form-group label="To Warehouse *" :error="$errors->first('to_warehouse_id')">
                <x-tallui-select name="to_warehouse_id" wire:model="to_warehouse_id" class="{{ $errors->has('to_warehouse_id') ? 'select-error' : '' }}">
                    <option value="">Select destination…</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>

            <x-tallui-form-group label="Shipping Rate / KG (BDT)">
                <x-tallui-input name="shipping_rate_per_kg" type="number" step="0.0001" min="0" wire:model="shipping_rate_per_kg" placeholder="0.00" />
            </x-tallui-form-group>

            <div class="md:col-span-2 lg:col-span-3">
                <x-tallui-form-group label="Notes">
                    <x-tallui-textarea name="notes" wire:model="notes" rows="2" placeholder="Transfer instructions, reference…" />
                </x-tallui-form-group>
            </div>
        </div>
    </x-tallui-card>

    {{-- Box Items — wire:key forces Alpine to reinit when source warehouse changes --}}
    <div wire:key="transfer-boxes-{{ $from_warehouse_id }}">
    <x-tallui-card padding="none" :shadow="true">
        <x-slot:actions>
            <x-tallui-button label="Add Box" icon="o-plus" class="btn-ghost btn-sm" type="button" wire:click="addBox" />
        </x-slot:actions>

        <div class="space-y-4 p-4">
            @forelse ($boxes as $boxIndex => $box)
                <div wire:key="trf-box-{{ $boxIndex }}" class="rounded-2xl border border-base-200 bg-base-50">
                    <div class="grid grid-cols-1 gap-4 border-b border-base-200 p-4 md:grid-cols-3">
                        <x-tallui-form-group label="Box Reference" :error="$errors->first('boxes.' . $boxIndex . '.box_code')">
                            <x-tallui-input
                                name="boxes.{{ $boxIndex }}.box_code"
                                wire:model="boxes.{{ $boxIndex }}.box_code"
                                placeholder="BOX-001"
                            />
                        </x-tallui-form-group>

                        <x-tallui-form-group label="Measured Box Weight (KG) *" :error="$errors->first('boxes.' . $boxIndex . '.measured_weight_kg')">
                            <x-tallui-input
                                name="boxes.{{ $boxIndex }}.measured_weight_kg"
                                type="number"
                                step="0.0001"
                                min="0"
                                wire:model="boxes.{{ $boxIndex }}.measured_weight_kg"
                            />
                        </x-tallui-form-group>

                        <div class="flex items-end justify-end">
                            <x-tallui-button
                                label="Remove Box"
                                icon="o-trash"
                                class="btn-ghost btn-sm text-error"
                                type="button"
                                wire:click="removeBox({{ $boxIndex }})"
                            />
                        </div>

                        <div class="md:col-span-3">
                            <x-tallui-form-group label="Box Notes" :error="$errors->first('boxes.' . $boxIndex . '.notes')">
                                <x-tallui-input
                                    name="boxes.{{ $boxIndex }}.notes"
                                    wire:model="boxes.{{ $boxIndex }}.notes"
                                    placeholder="Box condition, route note, seal number…"
                                />
                            </x-tallui-form-group>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="table table-sm w-full">
                            <thead>
                                <tr class="bg-base-100 text-xs text-base-content/50 uppercase">
                                    <th class="pl-5">Product</th>
                                    <th class="w-24">Available</th>
                                    <th class="w-32">Qty to Send</th>
                                    <th>Notes</th>
                                    <th class="pr-5 w-16"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-base-200">
                                @foreach ($box['items'] as $itemIndex => $item)
                                    <tr wire:key="trf-box-{{ $boxIndex }}-item-{{ $itemIndex }}">
                                        <td class="pl-5 py-2">
                                            @if ($from_warehouse_id)
                                            <div
                                                x-data="{
                                                    products: @js($productsJson),
                                                    search: '',
                                                    open: false,
                                                    rect: {},
                                                    get filtered() {
                                                        const q = this.search.toLowerCase().trim();
                                                        const list = this.products;
                                                        if (!q) return list.slice(0, 15);
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
                                                        $wire.set('boxes.{{ $boxIndex }}.items.{{ $itemIndex }}.product_id', p.id);
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
                                                <div x-ref="anchor" class="max-w-sm">
                                                    <input
                                                        type="text"
                                                        x-model="search"
                                                        x-on:focus="updateRect(); open = true"
                                                        x-on:input="open = true"
                                                        placeholder="Search name, SKU or barcode…"
                                                        autocomplete="off"
                                                        class="input input-sm input-bordered w-full {{ $errors->has('boxes.' . $boxIndex . '.items.' . $itemIndex . '.product_id') ? 'input-error' : '' }}"
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
                                                            bottom: (window.innerHeight - rect.top + 4) + 'px',
                                                            left: rect.left + 'px',
                                                            width: Math.max(320, rect.width) + 'px',
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
                                                                    <div class="text-xs text-base-content/50">
                                                                        <span x-show="p.sku" x-text="p.sku"></span>
                                                                        <span x-show="p.sku && p.barcode"> · </span>
                                                                        <span x-show="p.barcode" x-text="p.barcode"></span>
                                                                    </div>
                                                                </div>
                                                                <div class="shrink-0 text-right text-xs">
                                                                    <div class="font-mono text-success" x-text="parseFloat(p.available).toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 4})"></div>
                                                                    <div class="text-base-content/40" x-show="p.weight_kg" x-text="p.weight_kg + ' kg'"></div>
                                                                </div>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </template>
                                                @error('boxes.' . $boxIndex . '.items.' . $itemIndex . '.product_id')
                                                    <p class="mt-1 text-xs text-error">{{ $message }}</p>
                                                @enderror
                                            </div>
                                            @else
                                                <input
                                                    type="text"
                                                    disabled
                                                    placeholder="Select a source warehouse first…"
                                                    class="input input-sm input-bordered w-full max-w-sm cursor-not-allowed bg-base-200 opacity-60"
                                                />
                                            @endif
                                        </td>
                                        <td class="py-2 text-sm text-base-content/70">
                                            @if ($from_warehouse_id && ($item['product_id'] ?? null))
                                                {{ rtrim(rtrim(number_format((float) ($availableStock->get($item['product_id']) ?? 0), 4, '.', ''), '0'), '.') }}
                                            @else
                                                <span class="text-base-content/30">—</span>
                                            @endif
                                        </td>
                                        <td class="py-2">
                                            <x-tallui-input
                                                name="boxes.{{ $boxIndex }}.items.{{ $itemIndex }}.qty_sent"
                                                type="number"
                                                step="0.0001"
                                                min="0"
                                                wire:model="boxes.{{ $boxIndex }}.items.{{ $itemIndex }}.qty_sent"
                                                class="input-sm text-right w-28"
                                            />
                                        </td>
                                        <td class="py-2">
                                            <x-tallui-input
                                                name="boxes.{{ $boxIndex }}.items.{{ $itemIndex }}.notes"
                                                wire:model="boxes.{{ $boxIndex }}.items.{{ $itemIndex }}.notes"
                                                class="input-sm w-full"
                                                placeholder="Optional…"
                                            />
                                        </td>
                                        <td class="pr-5 py-2 text-right">
                                            <x-tallui-button
                                                icon="o-trash"
                                                class="btn-ghost btn-xs text-error"
                                                type="button"
                                                wire:click="removeItem({{ $boxIndex }}, {{ $itemIndex }})"
                                            />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="flex justify-end border-t border-base-200 p-4">
                        <x-tallui-button
                            label="Add Product"
                            icon="o-plus"
                            class="btn-ghost btn-sm"
                            type="button"
                            wire:click="addItem({{ $boxIndex }})"
                        />
                    </div>
                </div>
            @empty
                <div class="py-6">
                    <x-tallui-empty-state title="No boxes yet" description="Add the shipment boxes first, then enter the products inside each box." icon="o-cube" size="sm">
                        <x-tallui-button label="Add Box" icon="o-plus" class="btn-primary btn-sm" type="button" wire:click="addBox" />
                    </x-tallui-empty-state>
                </div>
            @endforelse
        </div>
    </x-tallui-card>
    </div>{{-- end wire:key wrapper --}}

    <div class="flex justify-end gap-2">
        <x-tallui-button label="Cancel" :link="route('inventory.shipments.index')" class="btn-ghost" />
        <x-tallui-button label="Create Shipment" icon="o-check" class="btn-primary" type="submit" :spinner="'save'" />
    </div>

</form>
</div>

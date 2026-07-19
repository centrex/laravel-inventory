<div>
<x-tallui-notification />

<x-tallui-page-header
    :title="$isEditing ? 'Edit ' . $documentLabel : 'New ' . $documentLabel"
    :subtitle="$documentLabel === 'Requisition' ? 'Capture internal purchasing demand before supplier confirmation.' : ($isEditing ? 'Review supplier lines, update draft details, and print or export purchase documents.' : 'Draft inbound purchases with multi-line item entry.')"
    icon="o-arrow-down-tray"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => $documentLabel === 'Requisition' ? 'Requisitions' : 'Purchase Orders', 'href' => route($routeBase . '.index')],
            ['label' => $isEditing ? 'Edit ' . $documentLabel : 'New ' . $documentLabel],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-badge type="info">Purchasing</x-tallui-badge>
        @if ($isEditing && $record && Route::has('erp.documents.purchases.print'))
            <x-tallui-button label="Print" icon="o-printer" :link="route('erp.documents.purchases.print', ['purchaseOrder' => $record->getKey()])" class="btn-ghost btn-sm" />
        @endif
        @if ($isEditing && $record && Route::has('erp.documents.purchases.pdf'))
            <x-tallui-button label="PDF" icon="o-arrow-down-tray" :link="route('erp.documents.purchases.pdf', ['purchaseOrder' => $record->getKey()])" :no-wire-navigate="true" class="btn-ghost btn-sm" />
        @endif
    </x-slot:actions>
</x-tallui-page-header>

<form wire:submit="save" wire:key="purchase-order-form-{{ $warehouse_id ?? 'none' }}-{{ $form_refresh_key }}" class="space-y-4">

    {{-- Header --}}
    <x-tallui-card title="Order Details" subtitle="Supplier, warehouse, and cost settings." icon="o-document-arrow-down" :shadow="true">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <x-tallui-form-group label="Warehouse *" :error="$errors->first('warehouse_id')">
                <x-tallui-select name="warehouse_id" wire:model.live="warehouse_id" class="{{ $errors->has('warehouse_id') ? 'select-error' : '' }}">
                    <option value="">Select warehouse…</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>

            <x-tallui-form-group label="Supplier *" :error="$errors->first('supplier_id')">
                <div wire:key="purchase-supplier-select-{{ $supplier_id ?? 'none' }}-{{ $form_refresh_key }}">
                    <x-tallui-select
                        name="supplier_id"
                        wire:model.live="supplier_id"
                        :value="$supplier_id"
                        searchable
                        placeholder="Search supplier…"
                        :options="$selectedSupplierOptions"
                        :search-url="parse_url(route('inventory.async-select', ['resource' => 'suppliers']), PHP_URL_PATH)"
                        class="{{ $errors->has('supplier_id') ? 'select-error' : '' }}"
                    />
                </div>
            </x-tallui-form-group>

            <x-tallui-form-group label="Expected Date">
                <x-tallui-input name="expected_at" type="date" wire:model="expected_at" />
            </x-tallui-form-group>

            <x-tallui-form-group label="Currency" :error="$errors->first('currency')">
                <div wire:key="currency-select-{{ $currency }}">
                    <select
                        name="currency"
                        wire:model.live="currency"
                        class="select select-bordered select-sm w-full {{ $errors->has('currency') ? 'select-error' : '' }}"
                    >
                        @foreach ($currencies as $code => $label)
                            <option value="{{ $code }}" @selected($currency === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </x-tallui-form-group>

            <x-tallui-form-group label="Exchange Rate (to base)" :error="$errors->first('exchange_rate')">
                <div class="relative">
                    <x-tallui-input
                        name="exchange_rate"
                        type="number"
                        step="0.0001"
                        wire:model="exchange_rate"
                        placeholder="Auto-fetched on currency change"
                    />
                    <div wire:loading wire:target="updatedCurrency"
                        class="absolute inset-y-0 right-3 flex items-center pointer-events-none">
                        <span class="loading loading-spinner loading-xs text-primary"></span>
                    </div>
                </div>
                @if($exchange_rate)
                    <p class="text-xs text-base-content/50 mt-1">
                        1 {{ $currency }} = {{ $exchange_rate }} {{ config('inventory.base_currency', 'BDT') }}
                    </p>
                @endif
            </x-tallui-form-group>

            <x-tallui-form-group label="Tax (Local)">
                <x-tallui-input name="tax_local" type="number" step="0.0001" wire:model="tax_local" placeholder="0.00" />
            </x-tallui-form-group>

            <x-tallui-form-group label="Shipping (Local)">
                <x-tallui-input name="shipping_local" type="number" step="0.0001" wire:model="shipping_local" placeholder="0.00" />
            </x-tallui-form-group>

            <x-tallui-form-group label="Other Charges (BDT)">
                <x-tallui-input name="other_charges_amount" type="number" step="0.0001" wire:model="other_charges_amount" placeholder="0.00" />
            </x-tallui-form-group>

            <div class="md:col-span-2 lg:col-span-3">
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-base-200 bg-base-50 px-4 py-3">
                    <div>
                        <div class="text-xs uppercase text-base-content/50">Pricing Currency</div>
                        <div class="font-mono text-sm font-semibold">{{ config('inventory.base_currency', 'BDT') }}</div>
                    </div>
                    <x-tallui-button
                        :label="filled($notes) ? 'View Note' : 'Add Note'"
                        :icon="filled($notes) || $show_notes ? 'o-chat-bubble-left-ellipsis' : 'o-plus-circle'"
                        class="btn-ghost btn-sm"
                        type="button"
                        wire:click="toggleNotes"
                    />
                </div>
                @if ($show_notes)
                    <div class="mt-3">
                        <x-tallui-form-group label="Notes">
                            <x-tallui-textarea name="notes" wire:model="notes" rows="2" placeholder="Internal notes, delivery instructions…" />
                        </x-tallui-form-group>
                    </div>
                @endif
            </div>
        </div>
    </x-tallui-card>

    {{-- Line Items --}}
    <x-tallui-card padding="none" :shadow="true">
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                        <th class="pl-5 w-64">Product</th>
                        <th class="w-36">Barcode</th>
                        <th class="w-28">Qty Ordered</th>
                        <th class="w-24">On Hand</th>
                        <th class="w-36">Unit Price (Local)</th>
                        <th class="pr-5 w-28 text-right">Options</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-200">
                    @forelse ($items as $index => $item)
                        <tr wire:key="po-item-{{ $index }}" class="even:bg-base-200/50 hover:bg-base-200">
                            <td class="pl-5 py-2">
                                <div wire:key="purchase-product-select-{{ $index }}-{{ $warehouse_id ?? 'none' }}-{{ $supplier_id ?? 'none' }}-{{ $item['product_key'] ?? 'none' }}-{{ $form_refresh_key }}">
                                    <x-tallui-select
                                        name="items.{{ $index }}.product_key"
                                        wire:model.live="items.{{ $index }}.product_key"
                                        :value="$item['product_key'] ?? null"
                                        searchable
                                        placeholder="Search product or variant…"
                                        :options="isset($selectedProductOptions[$item['product_key'] ?? '']) ? [($item['product_key'] ?? '') => $selectedProductOptions[$item['product_key'] ?? '']] : []"
                                        :search-url="parse_url(route('inventory.async-select', ['resource' => 'purchase-products']), PHP_URL_PATH)"
                                        :disabled="!$warehouse_id || !$supplier_id"
                                        class="select-sm w-full"
                                    />
                                    @if (!$item['product_key'] && (!$warehouse_id || !$supplier_id))
                                        <p class="mt-1 text-xs text-warning">Select a warehouse and supplier first.</p>
                                    @endif
                                    <input type="hidden" wire:model="items.{{ $index }}.product_id" />
                                    <input type="hidden" wire:model="items.{{ $index }}.variant_id" />
                                </div>
                            </td>
                            <td class="py-2">
                                <x-tallui-input
                                    wire:key="po-barcode-{{ $index }}-{{ $item['product_id'] ?? 'none' }}"
                                    name="items.{{ $index }}.barcode"
                                    wire:model="items.{{ $index }}.barcode"
                                    class="input-sm w-full font-mono"
                                    disabled
                                />
                            </td>
                            <td class="py-2">
                                <x-tallui-input
                                    name="items.{{ $index }}.qty_ordered"
                                    type="number" step="1" min="0"
                                    wire:model="items.{{ $index }}.qty_ordered"
                                    class="input-sm text-right w-full"
                                />
                            </td>
                            <td class="py-2 text-sm text-base-content/70">
                                {{ number_format((float) ($onHandStock->get(($item['product_id'] ?? 0) . ':' . (int) ($item['variant_id'] ?? 0))?->qty_on_hand ?? 0), 4) }}
                            </td>
                            <td class="py-2">
                                <x-tallui-input
                                    name="items.{{ $index }}.unit_price_local"
                                    type="number" step="0.0001" min="0"
                                    wire:model="items.{{ $index }}.unit_price_local"
                                    class="input-sm text-right w-full"
                                />
                            </td>
                            <td class="pr-5 py-2 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <x-tallui-button
                                        :icon="filled($item['notes'] ?? '') || ($item['show_notes'] ?? false) ? 'o-chat-bubble-left-ellipsis' : 'o-plus-circle'"
                                        class="btn-ghost btn-xs"
                                        type="button"
                                        wire:click="toggleItemNotes({{ $index }})"
                                        :tooltip="($item['show_notes'] ?? false) ? 'Hide notes' : 'Add notes'"
                                    />
                                    @if ($editable)
                                        <x-tallui-button
                                            icon="o-trash"
                                            class="btn-ghost btn-xs text-error"
                                            type="button"
                                            wire:click="removeItem({{ $index }})"
                                        />
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @if (($item['show_notes'] ?? false) || filled($item['notes'] ?? ''))
                            <tr wire:key="po-item-notes-{{ $index }}" class="bg-base-200/30">
                                <td colspan="5" class="px-5 py-3">
                                    <x-tallui-form-group label="Line Note">
                                        <x-tallui-textarea
                                            name="items.{{ $index }}.notes"
                                            wire:model="items.{{ $index }}.notes"
                                            rows="2"
                                            placeholder="Optional line-specific note…"
                                        />
                                    </x-tallui-form-group>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="5" class="py-6 text-center">
                                <x-tallui-empty-state
                                    title="No items yet"
                                    description="Add at least one product line."
                                    icon="o-cube"
                                    size="sm"
                                >
                                    <x-tallui-button label="Add Line" icon="o-plus" class="btn-primary btn-sm" type="button" wire:click="addItem" />
                                </x-tallui-empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($editable)
            <x-slot:footer>
                <x-tallui-button label="Add Line" icon="o-plus" class="btn-ghost btn-sm" type="button" wire:click="addItem" />
            </x-slot:footer>
        @endif
    </x-tallui-card>

    {{-- Footer actions --}}
    <div class="flex justify-end gap-2">
        <x-tallui-button :label="$documentLabel === 'Requisition' ? 'Back to Requisitions' : 'Back to Purchases'" :link="route($routeBase . '.index')" class="btn-ghost" />
        @if ($editable)
            <x-tallui-button :label="$isEditing ? 'Update ' . $documentLabel : 'Create ' . $documentLabel" icon="o-check" class="btn-primary" type="submit" :spinner="'save'" />
        @endif
    </div>

</form>
</div>

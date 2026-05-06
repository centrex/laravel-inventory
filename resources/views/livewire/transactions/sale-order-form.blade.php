<div>
<x-tallui-notification />

<x-tallui-dialog id="sale-order-credit-limit-dialog" type="warning" title="Credit limit exceeded" size="lg">
    <div class="text-left">
        {{ $credit_limit_dialog_message }}
    </div>
    <x-slot:footer>
        <x-tallui-button label="Close" class="btn-ghost" x-on:click="open = false" />
        <x-tallui-button label="Review Override" class="btn-primary" x-on:click="open = false" />
    </x-slot:footer>
</x-tallui-dialog>

<x-tallui-page-header
    :title="$isEditing ? 'Edit ' . $documentLabel : 'New ' . $documentLabel"
    :subtitle="$documentLabel === 'Quotation' ? 'Prepare customer-ready pricing before stock is committed.' : ($isEditing ? 'Review line items, update draft details, and print or export invoice documents.' : 'Capture outbound sales with tier-based or automatic pricing.')"
    icon="o-shopping-cart"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => $documentLabel === 'Quotation' ? 'Quotations' : 'Sale Orders', 'href' => route($routeBase . '.index')],
            ['label' => $isEditing ? 'Edit ' . $documentLabel : 'New ' . $documentLabel],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-badge type="success">Sales</x-tallui-badge>
        @if ($isEditing && $record && Route::has('erp.documents.sales.print'))
            <x-tallui-button label="Print" icon="o-printer" :link="route('erp.documents.sales.print', ['saleOrder' => $record->getKey()])" class="btn-ghost btn-sm" />
        @endif
        @if ($isEditing && $record && Route::has('erp.documents.sales.pdf'))
            <x-tallui-button label="PDF" icon="o-arrow-down-tray" :link="route('erp.documents.sales.pdf', ['saleOrder' => $record->getKey()])" :no-wire-navigate="true" class="btn-ghost btn-sm" />
        @endif
    </x-slot:actions>
</x-tallui-page-header>

<form wire:submit="save" wire:key="sale-order-form-{{ $warehouse_id ?? 'none' }}-{{ $form_refresh_key }}" class="space-y-4">

    {{-- Header --}}
    <x-tallui-card title="Order Details" subtitle="Customer, default price tier, and order adjustments." icon="o-banknotes" :shadow="true">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <x-tallui-form-group label="Warehouse *" :error="$errors->first('warehouse_id')">
                <x-tallui-select name="warehouse_id" wire:model.live="warehouse_id" class="{{ $errors->has('warehouse_id') ? 'select-error' : '' }}">
                    <option value="">Select warehouse…</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>

            <x-tallui-form-group label="Customer">
                <div class="flex items-start gap-2">
                    <div class="flex-1" wire:key="sale-customer-select-{{ $customer_id ?? 'none' }}-{{ $form_refresh_key }}">
                        <x-tallui-select
                            name="customer_id"
                            wire:model.live="customer_id"
                            :value="$customer_id"
                            searchable
                            placeholder="Search customer or leave empty for walk-in"
                            :options="$selectedCustomerOptions"
                            :search-url="parse_url(route('inventory.async-select', ['resource' => 'customers']), PHP_URL_PATH)"
                        />
                    </div>
                    @if ($customer_id)
                        <x-tallui-button
                            type="button"
                            icon="o-x-mark"
                            class="btn-ghost btn-sm mt-0.5"
                            wire:click="$set('customer_id', null)"
                            :tooltip="'Clear customer'"
                        />
                    @endif
                </div>
            </x-tallui-form-group>

            <x-tallui-form-group label="Default Price Tier">
                <x-tallui-select name="price_tier_code" wire:model.live="price_tier_code">
                    @foreach ($priceTiers as $tier)
                        <option value="{{ $tier['code'] }}">{{ $tier['name'] }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>

            <x-tallui-form-group label="Tax (Local)">
                <x-tallui-input name="tax_local" type="number" step="0.0001" wire:model="tax_local" placeholder="0.00" />
            </x-tallui-form-group>

            <x-tallui-form-group label="Discount (Local)">
                <x-tallui-input name="discount_local" type="number" step="0.0001" wire:model="discount_local" placeholder="0.00" />
            </x-tallui-form-group>

            <x-tallui-form-group label="Shipping (Local)">
                <x-tallui-input name="shipping_local" type="number" step="0.0001" wire:model="shipping_local" placeholder="0.00" />
            </x-tallui-form-group>

            <div class="md:col-span-2 lg:col-span-3">
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-base-200 bg-base-50 px-4 py-3">
                    <div>
                        <div class="text-xs uppercase text-base-content/50">Pricing Currency</div>
                        <div class="font-mono text-sm font-semibold">{{ $currency ?: 'Auto' }}</div>
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

        @if ($selectedCustomer && $customerCreditSnapshot)
            <div class="mt-5 rounded-xl border border-base-200 bg-base-50 p-4">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <div class="text-sm font-semibold text-base-content">Customer Credit Snapshot</div>
                        <div class="mt-1 text-xs text-base-content/60">
                            Current open exposure and available room before this order is posted.
                        </div>
                    </div>
                    <x-tallui-button
                        label="Open Customer History"
                        icon="o-clock"
                        :link="route('inventory.entities.customers.edit', ['recordId' => $selectedCustomer->id]) . '#history'"
                        class="btn-ghost btn-sm"
                    />
                </div>

                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div class="rounded-xl border border-base-200 bg-base-100 p-3">
                        <div class="text-xs uppercase text-base-content/50">Credit Limit</div>
                        <div class="mt-1 text-lg font-semibold">{{ number_format((float) $customerCreditSnapshot['credit_limit_amount'], 2) }} BDT</div>
                    </div>
                    <div class="rounded-xl border border-base-200 bg-base-100 p-3">
                        <div class="text-xs uppercase text-base-content/50">Open Exposure</div>
                        <div class="mt-1 text-lg font-semibold">{{ number_format((float) $customerCreditSnapshot['outstanding_exposure'], 2) }} BDT</div>
                    </div>
                    <div class="rounded-xl border border-base-200 bg-base-100 p-3">
                        <div class="text-xs uppercase text-base-content/50">Available Credit</div>
                        <div class="mt-1 text-lg font-semibold {{ (float) $customerCreditSnapshot['available_credit_amount'] < 0 ? 'text-error' : '' }}">
                            {{ number_format((float) $customerCreditSnapshot['available_credit_amount'], 2) }} BDT
                        </div>
                    </div>
                </div>

                <div class="mt-4 rounded-xl border border-base-200 bg-base-100">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left"
                        wire:click="toggleCreditOverrideOptions"
                    >
                        <span>
                            <span class="block text-sm font-semibold text-base-content">Credit Override</span>
                            <span class="block text-xs text-base-content/60">Use only when this order needs higher-authority approval.</span>
                        </span>
                        <x-tallui-icon :name="$show_credit_override_options ? 'o-chevron-up' : 'o-chevron-down'" class="h-4 w-4 text-base-content/60" />
                    </button>

                    @if ($show_credit_override_options)
                        <div class="grid grid-cols-1 gap-4 border-t border-base-200 p-4 md:grid-cols-2">
                            <div class="flex items-start gap-3">
                                <x-tallui-checkbox
                                    name="credit_override"
                                    label="Allow higher-authority credit override"
                                    wire:model="credit_override"
                                />
                            </div>

                            <x-tallui-form-group label="Override Reason / Approval Note" :error="$errors->first('credit_override_notes')">
                                <x-tallui-textarea
                                    name="credit_override_notes"
                                    wire:model="credit_override_notes"
                                    rows="2"
                                    placeholder="Required when the order will go over the customer credit limit."
                                />
                            </x-tallui-form-group>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </x-tallui-card>

    {{-- Line Items --}}
    <x-tallui-card padding="none" :shadow="true">
        <x-slot:actions>
            @if ($editable)
                <x-tallui-button label="Add Line" icon="o-plus" class="btn-ghost btn-sm" type="button" wire:click="addItem" />
            @endif
        </x-slot:actions>

        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                        <th class="pl-5 w-52">Product</th>
                        <th class="w-24">Qty</th>
                        <th class="w-24">Available</th>
                        <th class="w-32">Tier Override</th>
                        <th class="w-36">Barcode</th>
                        <th class="w-32">Unit Price ({{ $currency ?: 'Local' }})</th>
                        <th class="w-24">Discount %</th>
                        <th class="pr-5 w-28 text-right">Options</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-200">
                    @forelse ($items as $index => $item)
                        <tr wire:key="so-item-{{ $index }}" class="hover:bg-base-50">
                            <td class="pl-5 py-2">
                                <div wire:key="sale-product-select-{{ $index }}-{{ $warehouse_id ?? 'none' }}-{{ $item['product_key'] ?? 'none' }}-{{ $form_refresh_key }}">
                                    <x-tallui-select
                                        name="items.{{ $index }}.product_key"
                                        wire:model.live="items.{{ $index }}.product_key"
                                        :value="$item['product_key'] ?? null"
                                        searchable
                                        placeholder="Search product or variant…"
                                        :options="isset($selectedProductOptions[$item['product_key'] ?? '']) ? [($item['product_key'] ?? '') => $selectedProductOptions[$item['product_key'] ?? '']] : []"
                                        :search-url="parse_url(route('inventory.async-select', ['resource' => 'sale-products']), PHP_URL_PATH) . ($warehouse_id ? '?warehouse_id=' . $warehouse_id : '')"
                                        :disabled="filled($item['product_key'] ?? null)"
                                        class="select-sm w-full"
                                    />
                                    <input type="hidden" wire:model="items.{{ $index }}.product_id" />
                                    <input type="hidden" wire:model="items.{{ $index }}.variant_id" />
                                </div>
                            </td>
                            <td class="py-2">
                                <x-tallui-input name="items.{{ $index }}.qty_ordered" type="number" step="0.0001" min="0" wire:model="items.{{ $index }}.qty_ordered" class="input-sm text-right w-full" />
                            </td>
                            <td class="py-2 text-sm text-base-content/70">
                                {{ number_format((float) ($availableStock->get(($item['product_id'] ?? 0) . ':' . (int) ($item['variant_id'] ?? 0))?->qtyAvailable() ?? 0), 4) }}
                            </td>
                            <td class="py-2">
                                <x-tallui-select name="items.{{ $index }}.price_tier_code" wire:model.live="items.{{ $index }}.price_tier_code" class="select-sm w-full">
                                    <option value="">Default</option>
                                    @foreach ($priceTiers as $tier)
                                        <option value="{{ $tier['code'] }}">{{ $tier['name'] }}</option>
                                    @endforeach
                                </x-tallui-select>
                            </td>
                            <td class="py-2">
                                <x-tallui-input
                                    wire:key="so-barcode-{{ $index }}-{{ $item['product_id'] ?? 'none' }}"
                                    name="items.{{ $index }}.barcode"
                                    wire:model="items.{{ $index }}.barcode"
                                    class="input-sm w-full font-mono"
                                    disabled
                                />
                            </td>
                            <td class="py-2">
                                <x-tallui-input
                                    wire:key="so-unit-price-{{ $index }}-{{ $item['product_id'] ?? 'none' }}-{{ $item['price_tier_code'] ?: $price_tier_code }}"
                                    name="items.{{ $index }}.unit_price_local"
                                    type="number"
                                    step="0.0001"
                                    min="0"
                                    wire:model="items.{{ $index }}.unit_price_local"
                                    class="input-sm text-right w-full"
                                />
                            </td>
                            <td class="py-2">
                                <x-tallui-input name="items.{{ $index }}.discount_pct" type="number" step="0.01" min="0" max="100" wire:model="items.{{ $index }}.discount_pct" class="input-sm text-right w-full" placeholder="0" />
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
                                        <x-tallui-button icon="o-trash" class="btn-ghost btn-xs text-error" type="button" wire:click="removeItem({{ $index }})" />
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @if (($item['show_notes'] ?? false) || filled($item['notes'] ?? ''))
                            <tr wire:key="so-item-notes-{{ $index }}" class="bg-base-50/60">
                                <td colspan="8" class="px-5 py-3">
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
                            <td colspan="8" class="py-6 text-center">
                                <x-tallui-empty-state title="No items yet" description="Add at least one product line." icon="o-shopping-bag" size="sm">
                                    <x-tallui-button label="Add Line" icon="o-plus" class="btn-primary btn-sm" type="button" wire:click="addItem" />
                                </x-tallui-empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-tallui-card>

    <div class="flex justify-end gap-2">
        <x-tallui-button :label="$documentLabel === 'Quotation' ? 'Back to Quotations' : 'Back to Sales'" :link="route($routeBase . '.index')" class="btn-ghost" />
        @if ($editable)
            <x-tallui-button :label="$isEditing ? 'Update ' . $documentLabel : 'Create ' . $documentLabel" icon="o-check" class="btn-primary" type="submit" :spinner="'save'" />
        @endif
    </div>

</form>
</div>

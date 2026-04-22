<div>
<x-tallui-notification />

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

<form wire:submit="save" class="space-y-4">

    {{-- Header --}}
    <x-tallui-card title="Order Details" subtitle="Customer, pricing tier, and currency settings." icon="o-banknotes" :shadow="true">
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
                <x-tallui-select name="customer_id" wire:model="customer_id">
                    <option value="">Walk-in / none</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>

            <x-tallui-form-group label="Default Price Tier">
                <x-tallui-select name="price_tier_code" wire:model.live="price_tier_code">
                    @foreach ($priceTiers as $tier)
                        <option value="{{ $tier['code'] }}">{{ $tier['name'] }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>

            <x-tallui-form-group label="Currency">
                <x-tallui-input name="currency" wire:model="currency" placeholder="BDT" />
            </x-tallui-form-group>

            <x-tallui-form-group label="Exchange Rate (BDT)">
                <x-tallui-input name="exchange_rate" type="number" step="0.0001" wire:model="exchange_rate" />
            </x-tallui-form-group>

            <x-tallui-form-group label="Tax (Local)">
                <x-tallui-input name="tax_local" type="number" step="0.0001" wire:model="tax_local" placeholder="0.00" />
            </x-tallui-form-group>

            <x-tallui-form-group label="Discount (Local)">
                <x-tallui-input name="discount_local" type="number" step="0.0001" wire:model="discount_local" placeholder="0.00" />
            </x-tallui-form-group>

            <div class="md:col-span-2 lg:col-span-3">
                <x-tallui-form-group label="Notes">
                    <x-tallui-textarea name="notes" wire:model="notes" rows="2" placeholder="Internal notes, delivery instructions…" />
                </x-tallui-form-group>
            </div>
        </div>

        @if ($selectedCustomer && $customerCreditSnapshot)
            <div class="mt-5 rounded-2xl border border-base-200 bg-base-50 p-4">
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

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="flex items-start gap-3 rounded-xl border border-base-200 bg-base-100 p-3">
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
                        <th class="w-32">Unit Price (Local)</th>
                        <th class="w-24">Discount %</th>
                        <th class="pr-5 w-28 text-right">Options</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-200">
                    @forelse ($items as $index => $item)
                        <tr wire:key="so-item-{{ $index }}" class="hover:bg-base-50">
                            <td class="pl-5 py-2">
                                <x-tallui-select name="items.{{ $index }}.product_id" wire:model.live="items.{{ $index }}.product_id" class="select-sm w-full">
                                    <option value="">Select product…</option>
                                    @foreach ($products as $product)
                                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                                    @endforeach
                                </x-tallui-select>
                            </td>
                            <td class="py-2">
                                <x-tallui-input name="items.{{ $index }}.qty_ordered" type="number" step="0.0001" min="0" wire:model="items.{{ $index }}.qty_ordered" class="input-sm text-right w-full" />
                            </td>
                            <td class="py-2 text-sm text-base-content/70">
                                {{ number_format((float) ($availableStock->get($item['product_id'] ?? 0)?->qtyAvailable() ?? 0), 4) }}
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
                                <x-tallui-input name="items.{{ $index }}.unit_price_local" type="number" step="0.0001" min="0" wire:model="items.{{ $index }}.unit_price_local" class="input-sm text-right w-full" />
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
                                <td colspan="7" class="px-5 py-3">
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
                            <td colspan="7" class="py-6 text-center">
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

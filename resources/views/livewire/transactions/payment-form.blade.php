<div>
<x-tallui-notification />

<x-tallui-page-header title="Record Payment" subtitle="Posts a real payment in accounting against a sale or purchase order." icon="o-banknotes">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Payments', 'href' => route('inventory.payments.index')],
            ['label' => 'Record Payment'],
        ]" />
    </x-slot:breadcrumbs>
</x-tallui-page-header>

<form wire:submit="save" class="space-y-4">
    <x-tallui-card title="Payment Details" icon="o-clipboard-document-check" :shadow="true">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <x-tallui-form-group label="Type *" :error="$errors->first('target_type')">
                <x-tallui-select wire:model.live="target_type" class="{{ $errors->has('target_type') ? 'select-error' : '' }}">
                    <option value="sale">Sale (Invoice)</option>
                    <option value="purchase">Purchase (Bill)</option>
                </x-tallui-select>
            </x-tallui-form-group>

            @if ($target_type === 'sale')
                <x-tallui-form-group label="Sale Order *" :error="$errors->first('sale_order_id')">
                    <x-tallui-select wire:model="sale_order_id" class="{{ $errors->has('sale_order_id') ? 'select-error' : '' }}">
                        <option value="">Select sale order…</option>
                        @foreach ($saleOrders as $saleOrder)
                            <option value="{{ $saleOrder->id }}">{{ $saleOrder->so_number }} — due {{ number_format((float) $saleOrder->due_amount, 2) }}</option>
                        @endforeach
                    </x-tallui-select>
                </x-tallui-form-group>
            @else
                <x-tallui-form-group label="Purchase Order *" :error="$errors->first('purchase_order_id')">
                    <x-tallui-select wire:model="purchase_order_id" class="{{ $errors->has('purchase_order_id') ? 'select-error' : '' }}">
                        <option value="">Select purchase order…</option>
                        @foreach ($purchaseOrders as $purchaseOrder)
                            <option value="{{ $purchaseOrder->id }}">{{ $purchaseOrder->po_number }} — due {{ number_format((float) $purchaseOrder->due_amount, 2) }}</option>
                        @endforeach
                    </x-tallui-select>
                </x-tallui-form-group>
            @endif

            <x-tallui-form-group label="Amount *" :error="$errors->first('amount')">
                <x-tallui-input type="number" step="0.01" wire:model="amount" class="{{ $errors->has('amount') ? 'input-error' : '' }}" />
            </x-tallui-form-group>

            <x-tallui-form-group label="Payment Date *" :error="$errors->first('payment_date')">
                <x-tallui-input type="date" wire:model="payment_date" class="{{ $errors->has('payment_date') ? 'input-error' : '' }}" />
            </x-tallui-form-group>

            <x-tallui-form-group label="Method *" :error="$errors->first('payment_method')">
                <x-tallui-select wire:model="payment_method" class="{{ $errors->has('payment_method') ? 'select-error' : '' }}">
                    <option value="cash">Cash</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="check">Check</option>
                    <option value="card">Card</option>
                    <option value="mobile_banking">Mobile Banking</option>
                    <option value="other">Other</option>
                </x-tallui-select>
            </x-tallui-form-group>

            <x-tallui-form-group label="Deposit Account Code *" :error="$errors->first('account_code')" helper="Any active asset account (10xx/11xx); defaults to Cash.">
                <x-tallui-input wire:model="account_code" class="{{ $errors->has('account_code') ? 'input-error' : '' }}" />
            </x-tallui-form-group>

            <x-tallui-form-group label="Reference" :error="$errors->first('reference')">
                <x-tallui-input wire:model="reference" class="{{ $errors->has('reference') ? 'input-error' : '' }}" />
            </x-tallui-form-group>
        </div>
    </x-tallui-card>

    <div class="flex justify-end gap-2">
        <x-tallui-button label="Cancel" :link="route('inventory.payments.index')" class="btn-ghost" type="button" />
        <x-tallui-button label="Record Payment" icon="o-check" class="btn-primary" type="submit" />
    </div>
</form>
</div>

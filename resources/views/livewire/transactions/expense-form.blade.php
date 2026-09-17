<div>
<x-tallui-notification />

<x-tallui-page-header title="Record Expense" subtitle="Posts a standalone expense in accounting." icon="o-receipt-percent">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Expenses', 'href' => route('inventory.expenses.index')],
            ['label' => 'Record Expense'],
        ]" />
    </x-slot:breadcrumbs>
</x-tallui-page-header>

<form wire:submit="save" class="space-y-4">
    <x-tallui-card title="Expense Details" icon="o-clipboard-document-check" :shadow="true">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <x-tallui-form-group label="Account *" :error="$errors->first('account_id')">
                <x-tallui-select wire:model="account_id" class="{{ $errors->has('account_id') ? 'select-error' : '' }}">
                    <option value="">Select account…</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>

            <x-tallui-form-group label="Expense Date *" :error="$errors->first('expense_date')">
                <x-tallui-input type="date" wire:model="expense_date" class="{{ $errors->has('expense_date') ? 'input-error' : '' }}" />
            </x-tallui-form-group>

            <x-tallui-form-group label="Payment Method *" :error="$errors->first('payment_method')" helper="Cash posts and pays immediately; credit stays payable.">
                <x-tallui-select wire:model="payment_method" class="{{ $errors->has('payment_method') ? 'select-error' : '' }}">
                    <option value="cash">Cash</option>
                    <option value="credit">Credit</option>
                </x-tallui-select>
            </x-tallui-form-group>

            <x-tallui-form-group label="Subtotal *" :error="$errors->first('subtotal')">
                <x-tallui-input type="number" step="0.01" wire:model="subtotal" class="{{ $errors->has('subtotal') ? 'input-error' : '' }}" />
            </x-tallui-form-group>

            <x-tallui-form-group label="Tax Amount" :error="$errors->first('tax_amount')">
                <x-tallui-input type="number" step="0.01" wire:model="tax_amount" class="{{ $errors->has('tax_amount') ? 'input-error' : '' }}" />
            </x-tallui-form-group>

            <x-tallui-form-group label="Vendor Name" :error="$errors->first('vendor_name')">
                <x-tallui-input wire:model="vendor_name" class="{{ $errors->has('vendor_name') ? 'input-error' : '' }}" />
            </x-tallui-form-group>

            <x-tallui-form-group label="Reference" :error="$errors->first('reference')">
                <x-tallui-input wire:model="reference" class="{{ $errors->has('reference') ? 'input-error' : '' }}" />
            </x-tallui-form-group>

            <div class="md:col-span-2 lg:col-span-3">
                <x-tallui-form-group label="Notes" :error="$errors->first('notes')">
                    <x-tallui-textarea wire:model="notes" rows="2" />
                </x-tallui-form-group>
            </div>
        </div>
    </x-tallui-card>

    <div class="flex justify-end gap-2">
        <x-tallui-button label="Cancel" :link="route('inventory.expenses.index')" class="btn-ghost" type="button" />
        <x-tallui-button label="Record Expense" icon="o-check" class="btn-primary" type="submit" />
    </div>
</form>
</div>

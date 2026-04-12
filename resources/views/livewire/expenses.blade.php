<div>
<x-tallui-notification />

<x-tallui-page-header title="Expenses" subtitle="Track and manage business expenses" icon="o-credit-card">
    <x-slot:actions>
        <x-tallui-button wire:click="openCreate" icon="o-plus" class="btn-primary btn-sm">New Expense</x-tallui-button>
    </x-slot:actions>
</x-tallui-page-header>

{{-- Filters --}}
<x-tallui-card class="mb-4" padding="compact">
    <div class="flex flex-wrap gap-3 items-end p-1">
        <div class="flex-1 min-w-52">
            <x-tallui-form-group label="Search">
                <x-tallui-input wire:model.live.debounce.300ms="search" placeholder="Expense # or vendor…" class="input-sm" />
            </x-tallui-form-group>
        </div>
        <div class="w-36">
            <x-tallui-form-group label="Status">
                <x-tallui-select wire:model.live="statusFilter" class="select-sm">
                    <option value="">All</option>
                    <option value="draft">Draft</option>
                    <option value="approved">Approved</option>
                    <option value="partial">Partial</option>
                    <option value="paid">Paid</option>
                </x-tallui-select>
            </x-tallui-form-group>
        </div>
        <div class="w-40">
            <x-tallui-form-group label="From">
                <x-tallui-input type="date" wire:model.live="dateFrom" class="input-sm" />
            </x-tallui-form-group>
        </div>
        <div class="w-40">
            <x-tallui-form-group label="To">
                <x-tallui-input type="date" wire:model.live="dateTo" class="input-sm" />
            </x-tallui-form-group>
        </div>
    </div>
</x-tallui-card>

{{-- Expenses Table --}}
<x-tallui-card padding="none">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                    <th class="pl-5">Expense #</th>
                    <th>Vendor</th>
                    <th>Date</th>
                    <th>Due Date</th>
                    <th class="text-right">Total</th>
                    <th class="text-right">Balance</th>
                    <th>Status</th>
                    <th class="pr-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse($expenses as $expense)
                    <tr class="hover:bg-base-50">
                        <td class="pl-5 font-mono text-sm text-primary font-semibold">{{ $expense->expense_number }}</td>
                        <td class="text-sm font-medium">{{ $expense->vendor_name ?: '—' }}</td>
                        <td class="text-sm text-base-content/60">{{ $expense->expense_date->format('M d, Y') }}</td>
                        <td class="text-sm text-base-content/60">
                            {{ $expense->due_date ? $expense->due_date->format('M d, Y') : '—' }}
                        </td>
                        <td class="text-right text-sm font-mono font-medium">{{ number_format($expense->total, 2) }}</td>
                        <td class="text-right text-sm font-mono {{ $expense->balance > 0 ? 'text-warning' : 'text-success' }}">
                            {{ number_format($expense->balance, 2) }}
                        </td>
                        <td>
                            <x-tallui-badge :type="match($expense->status) {
                                'paid'     => 'success',
                                'approved' => 'info',
                                'partial'  => 'warning',
                                default    => 'neutral',
                            }">
                                {{ ucfirst($expense->status) }}
                            </x-tallui-badge>
                        </td>
                        <td class="pr-5">
                            <div class="flex justify-end gap-1">
                                @if($expense->status === 'draft')
                                    <x-tallui-button wire:click="postExpense({{ $expense->id }})"
                                        wire:confirm="Post expense {{ $expense->expense_number }}?"
                                        class="btn-info btn-xs" spinner="save">Post</x-tallui-button>
                                @endif
                                @if(in_array($expense->status, ['approved', 'partial']) && $expense->balance > 0)
                                    <x-tallui-button wire:click="openPayModal({{ $expense->id }})" class="btn-success btn-xs" spinner="pay">Pay</x-tallui-button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <x-tallui-empty-state title="No expenses found" description="Record your first expense" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-base-200">{{ $expenses->links() }}</div>
</x-tallui-card>

{{-- Create Expense Modal --}}
<x-tallui-modal id="expense-modal" title="New Expense" icon="o-credit-card" size="xl">
    <x-slot:trigger>
        <span x-effect="if ($wire.showModal) $dispatch('open-modal', 'expense-modal'); else $dispatch('close-modal', 'expense-modal')"></span>
    </x-slot:trigger>

    <form wire:submit.prevent="save" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Vendor Name">
                <x-tallui-input wire:model="vendor_name" placeholder="Vendor or payee name" />
            </x-tallui-form-group>
            @if($accounts->isNotEmpty())
            <x-tallui-form-group label="Expense Account">
                <x-tallui-select wire:model="account_id" class="{{ $errors->has('account_id') ? 'select-error' : '' }}">
                    <option value="">Select Account</option>
                    @foreach($accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</option>
                    @endforeach
                </x-tallui-select>
            </x-tallui-form-group>
            @endif
        </div>

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Expense Date *" :error="$errors->first('expense_date')">
                <x-tallui-input type="date" wire:model="expense_date" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Due Date">
                <x-tallui-input type="date" wire:model="due_date" />
            </x-tallui-form-group>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Reference">
                <x-tallui-input wire:model="reference" placeholder="Invoice #, receipt #…" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Payment Method">
                <x-tallui-select wire:model="payment_method">
                    <option value="cash">Cash</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="check">Check</option>
                    <option value="card">Card</option>
                    <option value="credit">Credit</option>
                </x-tallui-select>
            </x-tallui-form-group>
        </div>

        {{-- Line Items --}}
        <div>
            <div class="flex items-center justify-between mb-2">
                <label class="text-sm font-semibold text-base-content/70">Line Items</label>
                <x-tallui-button wire:click="addItem" icon="o-plus" class="btn-ghost btn-xs">Add Item</x-tallui-button>
            </div>
            <div class="space-y-2 max-h-56 overflow-y-auto pr-1">
                @foreach($items as $i => $item)
                    <div class="flex gap-2 items-start bg-base-50 border border-base-200 p-2 rounded-xl">
                        <div class="flex-1">
                            <x-tallui-input wire:model="items.{{ $i }}.description" placeholder="Description" class="input-sm" />
                        </div>
                        <input type="number" step="0.01" wire:model.lazy="items.{{ $i }}.quantity" placeholder="Qty" class="input input-sm w-20 border-base-300 text-right" />
                        <input type="number" step="0.01" wire:model.lazy="items.{{ $i }}.unit_price" placeholder="Price" class="input input-sm w-28 border-base-300 text-right" />
                        <input type="number" step="0.01" wire:model.lazy="items.{{ $i }}.tax_rate" placeholder="Tax%" class="input input-sm w-20 border-base-300 text-right" />
                        <x-tallui-button wire:click="removeItem({{ $i }})" icon="o-trash" class="btn-ghost btn-sm text-error" />
                    </div>
                @endforeach
            </div>
            <div class="mt-3 p-3 bg-base-50 rounded-xl border border-base-200 text-sm">
                <div class="flex justify-between mb-1">
                    <span class="text-base-content/60">Subtotal</span>
                    <span class="font-mono">{{ number_format($this->subtotal, 2) }}</span>
                </div>
                <div class="flex justify-between mb-1">
                    <span class="text-base-content/60">Tax</span>
                    <span class="font-mono">{{ number_format($this->taxTotal, 2) }}</span>
                </div>
                <div class="flex justify-between font-bold border-t border-base-200 pt-1 mt-1">
                    <span>Total</span>
                    <span class="font-mono">{{ number_format($this->total, 2) }}</span>
                </div>
            </div>
        </div>

        <x-tallui-form-group label="Notes">
            <x-tallui-textarea wire:model="notes" rows="2" placeholder="Optional notes…" />
        </x-tallui-form-group>
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="save" spinner="save" class="btn-primary">Record Expense</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>

{{-- Pay Expense Modal --}}
<x-tallui-modal id="pay-expense-modal" title="Record Expense Payment" icon="o-banknotes" size="md">
    <x-slot:trigger>
        <span x-effect="if ($wire.showPayModal) $dispatch('open-modal', 'pay-expense-modal'); else $dispatch('close-modal', 'pay-expense-modal')"></span>
    </x-slot:trigger>

    <form wire:submit.prevent="recordPayment" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Payment Date *" :error="$errors->first('pay_date')">
                <x-tallui-input type="date" wire:model="pay_date" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Amount *" :error="$errors->first('pay_amount')">
                <x-tallui-input type="number" step="0.01" wire:model="pay_amount" class="text-right" />
            </x-tallui-form-group>
        </div>
        <x-tallui-form-group label="Payment Method *">
            <x-tallui-select wire:model="pay_method">
                <option value="cash">Cash</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="check">Check</option>
                <option value="card">Card</option>
                <option value="other">Other</option>
            </x-tallui-select>
        </x-tallui-form-group>
        <x-tallui-form-group label="Reference">
            <x-tallui-input wire:model="pay_reference" placeholder="Transaction ID, check #…" />
        </x-tallui-form-group>
        <x-tallui-form-group label="Notes">
            <x-tallui-textarea wire:model="pay_notes" rows="2" placeholder="Payment notes…" />
        </x-tallui-form-group>
    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showPayModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="recordPayment" spinner="recordPayment" class="btn-warning">Record Payment</x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>
</div>

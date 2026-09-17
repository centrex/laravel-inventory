<div>
<x-tallui-notification />

<x-tallui-page-header title="Expenses" subtitle="Charges, discounts, and standalone expenses recorded in accounting, mirrored here for reporting." icon="o-receipt-percent">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Expenses'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-tallui-button label="Record Expense" icon="o-plus" :link="route('inventory.expenses.create')" class="btn-primary btn-sm" />
            <div class="w-44">
                <x-tallui-select wire:model.live="direction" class="select-sm">
                    <option value="">All directions</option>
                    <option value="sale">Sale</option>
                    <option value="purchase">Purchase</option>
                    <option value="standalone">Standalone</option>
                </x-tallui-select>
            </div>
        </div>
    </x-slot:actions>
</x-tallui-page-header>

<x-tallui-card padding="none" :shadow="true">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                    <th class="pl-5">Number</th>
                    <th>Direction</th>
                    <th>Party</th>
                    <th>Account</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th class="pr-5 text-right">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse ($expenses as $expense)
                    <tr class="even:bg-base-200/50 hover:bg-base-200">
                        <td class="pl-5 font-mono font-semibold">{{ $expense->expense_number ?? '—' }}</td>
                        <td>
                            <x-tallui-badge :type="match ($expense->direction) { 'sale' => 'success', 'purchase' => 'info', default => 'ghost' }">
                                {{ ucfirst($expense->direction) }}
                            </x-tallui-badge>
                        </td>
                        <td>{{ $expense->customer?->name ?? $expense->supplier?->name ?? '—' }}</td>
                        <td>{{ $expense->account_code ? "{$expense->account_code} — {$expense->account_name}" : '—' }}</td>
                        <td>{{ $expense->expense_date?->format('Y-m-d') ?? '—' }}</td>
                        <td>{{ $expense->status ? ucfirst($expense->status) : '—' }}</td>
                        <td class="pr-5 text-right font-semibold">{{ number_format((float) $expense->total, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="py-8">
                            <x-tallui-empty-state title="No expenses yet" description="Charges, discounts, and standalone expenses will appear here." icon="o-receipt-percent" size="sm">
                                <x-tallui-button label="Record Expense" icon="o-plus" :link="route('inventory.expenses.create')" class="btn-primary" />
                            </x-tallui-empty-state>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($expenses->hasPages())
        <div class="px-5 py-3 border-t border-base-200">
            {{ $expenses->links() }}
        </div>
    @endif
</x-tallui-card>
</div>

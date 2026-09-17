<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Http\Livewire\Transactions\Concerns\GuardsAgainstDuplicateSubmission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Records a standalone expense in laravel-accounting (Expense::create() + Accounting::
 * postExpense()) — a convenience front-end for inventory-only users, not a parallel
 * bookkeeping path. Sale/purchase-order-linked charges & discounts are recorded through
 * accounting's own InvoiceDetails/BillDetails pages; either way, inv_expenses picks up the
 * new row automatically via ExpenseMirrorObserver once it's created.
 */
#[Layout('layouts.app')]
class ExpenseFormPage extends Component
{
    use GuardsAgainstDuplicateSubmission;

    public ?int $account_id = null;

    public ?string $expense_date = null;

    public ?float $subtotal = null;

    public ?float $tax_amount = 0;

    public string $payment_method = 'cash'; // cash|credit

    public ?string $vendor_name = null;

    public ?string $reference = null;

    public ?string $notes = null;

    public function mount(): void
    {
        abort_unless(class_exists(\Centrex\Accounting\Facades\Accounting::class), 404);

        $this->initializeFormToken();
        $this->expense_date = now()->toDateString();
    }

    public function save()
    {
        Gate::authorize('inventory.expenses.manage');

        $validated = $this->validate([
            'account_id'     => ['required', 'integer'],
            'expense_date'   => ['required', 'date'],
            'subtotal'       => ['required', 'numeric', 'gt:0'],
            'tax_amount'     => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['required', 'string', 'in:cash,credit'],
            'vendor_name'    => ['nullable', 'string', 'max:200'],
            'reference'      => ['nullable', 'string', 'max:200'],
            'notes'          => ['nullable', 'string', 'max:500'],
        ]);

        return $this->onceForThisSubmission('inventory.expense.create', function () use ($validated) {
            $tax = (float) ($validated['tax_amount'] ?? 0);
            $total = round((float) $validated['subtotal'] + $tax, 4);

            $expense = \Centrex\Accounting\Models\Expense::create([
                'account_id'     => $validated['account_id'],
                'expense_date'   => $validated['expense_date'],
                'subtotal'       => $validated['subtotal'],
                'tax_amount'     => $tax,
                'total'          => $total,
                'currency'       => config('inventory.base_currency', 'BDT'),
                'payment_method' => $validated['payment_method'],
                'vendor_name'    => $validated['vendor_name'],
                'reference'      => $validated['reference'],
                'notes'          => $validated['notes'],
            ]);

            app(\Centrex\Accounting\Accounting::class)->postExpense($expense);

            $this->dispatch('notify', type: 'success', message: "Expense {$expense->expense_number} recorded.");

            return redirect()->route('inventory.expenses.index');
        });
    }

    public function render(): View
    {
        $accounts = class_exists(\Centrex\Accounting\Models\Account::class)
            ? \Centrex\Accounting\Models\Account::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])
            : collect();

        return view('inventory::livewire.transactions.expense-form', [
            'accounts' => $accounts,
        ]);
    }
}

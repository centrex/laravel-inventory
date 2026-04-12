<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Expenses;

use Centrex\Inventory\Models\{Expense, ExpenseItem};
use Illuminate\Support\Facades\DB;
use Livewire\{Component, WithPagination};

class ExpensesPage extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public bool $showModal = false;

    public bool $showPayModal = false;

    public ?int $expenseId = null;

    public ?int $account_id = null;

    public string $expense_date = '';

    public string $due_date = '';

    public string $notes = '';

    public string $payment_method = 'cash';

    public string $reference = '';

    public string $vendor_name = '';

    public array $items = [];

    public ?int $payingExpenseId = null;

    public string $pay_date = '';

    public string $pay_amount = '';

    public string $pay_method = 'cash';

    public string $pay_reference = '';

    public string $pay_notes = '';

    protected array $queryString = ['search', 'statusFilter'];

    public function mount(): void
    {
        $this->expense_date = now()->format('Y-m-d');
        $this->due_date     = now()->addDays(30)->format('Y-m-d');
        $this->addItem();
    }

    public function addItem(): void
    {
        $this->items[] = [
            'description' => '',
            'quantity'    => 1,
            'unit_price'  => 0,
            'tax_rate'    => 0,
        ];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function openCreate(): void
    {
        $this->reset(['expenseId', 'account_id', 'notes', 'reference', 'vendor_name', 'items']);
        $this->expense_date = now()->format('Y-m-d');
        $this->due_date     = now()->addDays(30)->format('Y-m-d');
        $this->addItem();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'account_id'          => 'nullable|integer',
            'expense_date'        => 'required|date',
            'items'               => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity'    => 'required|numeric|min:0.01',
            'items.*.unit_price'  => 'required|numeric|min:0',
        ]);

        DB::transaction(function (): void {
            $subtotal  = 0;
            $taxAmount = 0;

            foreach ($this->items as $item) {
                $amount    = $item['quantity'] * $item['unit_price'];
                $itemTax   = $amount * (($item['tax_rate'] ?? 0) / 100);
                $subtotal  += $amount;
                $taxAmount += $itemTax;
            }

            $expense = Expense::create([
                'account_id'     => $this->account_id,
                'expense_date'   => $this->expense_date,
                'due_date'       => $this->due_date ?: null,
                'subtotal'       => $subtotal,
                'tax_amount'     => $taxAmount,
                'total'          => $subtotal + $taxAmount,
                'currency'       => config('inventory.base_currency', 'BDT'),
                'status'         => 'draft',
                'payment_method' => $this->payment_method,
                'reference'      => $this->reference ?: null,
                'vendor_name'    => $this->vendor_name ?: null,
                'notes'          => $this->notes ?: null,
            ]);

            foreach ($this->items as $item) {
                $amount = $item['quantity'] * $item['unit_price'];
                $tax    = $amount * (($item['tax_rate'] ?? 0) / 100);

                ExpenseItem::create([
                    'expense_id'  => $expense->id,
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'],
                    'unit_price'  => $item['unit_price'],
                    'amount'      => $amount,
                    'tax_rate'    => $item['tax_rate'] ?? 0,
                    'tax_amount'  => $tax,
                ]);
            }
        });

        $this->dispatch('notify', type: 'success', message: 'Expense recorded successfully!');
        $this->showModal = false;
        $this->reset(['expenseId', 'account_id', 'items']);
    }

    public function postExpense(int $id): void
    {
        $expense = Expense::findOrFail($id);

        try {
            // Bridge to accounting if available
            if (class_exists(\Centrex\Accounting\Facades\Accounting::class)) {
                \Centrex\Accounting\Facades\Accounting::postExpense($expense);
            }

            $expense->update(['status' => 'approved']);
            $this->dispatch('notify', type: 'success', message: "Expense {$expense->expense_number} posted.");
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function openPayModal(int $id): void
    {
        $expense              = Expense::findOrFail($id);
        $this->payingExpenseId = $id;
        $this->pay_date       = now()->format('Y-m-d');
        $this->pay_amount     = number_format($expense->balance, 2, '.', '');
        $this->pay_method     = 'cash';
        $this->pay_reference  = '';
        $this->pay_notes      = '';
        $this->showPayModal   = true;
    }

    public function recordPayment(): void
    {
        $this->validate([
            'pay_date'   => 'required|date',
            'pay_amount' => 'required|numeric|min:0.01',
            'pay_method' => 'required|string',
        ]);

        $expense = Expense::findOrFail($this->payingExpenseId);

        try {
            if (class_exists(\Centrex\Accounting\Facades\Accounting::class)) {
                \Centrex\Accounting\Facades\Accounting::recordExpensePayment($expense, [
                    'date'      => $this->pay_date,
                    'amount'    => $this->pay_amount,
                    'method'    => $this->pay_method,
                    'reference' => $this->pay_reference ?: null,
                    'notes'     => $this->pay_notes ?: null,
                ]);
            } else {
                $paid = (float) $expense->paid_amount + (float) $this->pay_amount;
                $expense->update([
                    'paid_amount' => $paid,
                    'status'      => $paid >= (float) $expense->total ? 'paid' : 'partial',
                ]);
            }

            $this->dispatch('notify', type: 'success', message: 'Payment recorded successfully!');
            $this->showPayModal = false;
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function getSubtotalProperty(): float
    {
        return collect($this->items)->sum(fn ($i): int|float => ($i['quantity'] ?? 0) * ($i['unit_price'] ?? 0));
    }

    public function getTaxTotalProperty(): float
    {
        return collect($this->items)->sum(function ($i): float {
            $amount = ($i['quantity'] ?? 0) * ($i['unit_price'] ?? 0);

            return $amount * (($i['tax_rate'] ?? 0) / 100);
        });
    }

    public function getTotalProperty(): float
    {
        return $this->subtotal + $this->taxTotal;
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $expenses = Expense::query()
            ->when($this->search, fn ($q) => $q->where(function ($q): void {
                $q->where('expense_number', 'like', '%' . $this->search . '%')
                    ->orWhere('vendor_name', 'like', '%' . $this->search . '%')
                    ->orWhere('reference', 'like', '%' . $this->search . '%');
            }))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('expense_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('expense_date', '<=', $this->dateTo))
            ->latest('expense_date')
            ->paginate(config('inventory.per_page.expenses', 15));

        // Optionally load accounting Chart of Accounts for account selector
        $accounts = collect();

        if (class_exists(\Centrex\Accounting\Models\Account::class)) {
            $accounts = \Centrex\Accounting\Models\Account::where('type', 'expense')
                ->where('is_active', true)
                ->orderBy('code')
                ->get();
        }

        $layout = view()->exists('layouts.app')
            ? 'layouts.app'
            : 'components.layouts.app';

        return view('inventory::livewire.expenses', [
            'expenses' => $expenses,
            'accounts' => $accounts,
        ])->layout($layout, ['title' => __('Expenses')]);
    }
}

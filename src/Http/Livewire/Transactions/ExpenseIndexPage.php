<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Models\Expense;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\{Layout, Url};
use Livewire\{Component, WithPagination};

/** Read-only list of inv_expenses — the mirror table kept in sync by ExpenseMirrorObserver. */
#[Layout('layouts.app')]
class ExpenseIndexPage extends Component
{
    use WithPagination;

    #[Url(as: 'direction', except: '')]
    public string $direction = '';

    public function mount(): void
    {
        Gate::authorize('inventory.expenses.view');
    }

    public function render(): View
    {
        $expenses = Expense::query()
            ->with(['saleOrder', 'purchaseOrder', 'customer', 'supplier'])
            ->when($this->direction !== '', fn ($q) => $q->where('direction', $this->direction))
            ->latest('expense_date')
            ->latest('id')
            ->paginate(15);

        return view('inventory::livewire.transactions.expense-index', [
            'expenses' => $expenses,
        ]);
    }
}

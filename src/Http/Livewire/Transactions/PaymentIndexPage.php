<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Models\Payment;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\{Layout, Url};
use Livewire\{Component, WithPagination};

/** Read-only list of inv_payments — the mirror table kept in sync by PaymentMirrorObserver. */
#[Layout('layouts.app')]
class PaymentIndexPage extends Component
{
    use WithPagination;

    #[Url(as: 'direction', except: '')]
    public string $direction = '';

    public function mount(): void
    {
        Gate::authorize('inventory.payments.view');
    }

    public function render(): View
    {
        $payments = Payment::query()
            ->with(['saleOrder', 'purchaseOrder', 'customer', 'supplier'])
            ->when($this->direction !== '', fn ($q) => $q->where('direction', $this->direction))
            ->latest('payment_date')
            ->latest('id')
            ->paginate(15);

        return view('inventory::livewire.transactions.payment-index', [
            'payments' => $payments,
        ]);
    }
}

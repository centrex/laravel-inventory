<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Http\Livewire\Transactions\Concerns\GuardsAgainstDuplicateSubmission;
use Centrex\Inventory\Models\{PurchaseOrder, SaleOrder};
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Records a real payment in laravel-accounting (Accounting::recordInvoicePayment()/
 * recordBillPayment()) against a sale or purchase order — this is a convenience front-end for
 * inventory-only users, not a parallel bookkeeping path. inv_payments picks up the new row
 * automatically via PaymentMirrorObserver once the accounting call completes.
 */
#[Layout('layouts.app')]
class PaymentFormPage extends Component
{
    use GuardsAgainstDuplicateSubmission;

    public string $target_type = 'sale'; // sale|purchase

    public ?int $sale_order_id = null;

    public ?int $purchase_order_id = null;

    public ?float $amount = null;

    public ?string $payment_date = null;

    public string $payment_method = 'cash';

    public string $account_code = '1000';

    public ?string $reference = null;

    public function mount(): void
    {
        abort_unless(class_exists(\Centrex\Accounting\Facades\Accounting::class), 404);

        $this->initializeFormToken();
        $this->payment_date = now()->toDateString();
    }

    public function updatedTargetType(): void
    {
        $this->sale_order_id = null;
        $this->purchase_order_id = null;
    }

    public function save()
    {
        Gate::authorize('inventory.payments.manage');

        $validated = $this->validate([
            'target_type'       => ['required', 'in:sale,purchase'],
            'sale_order_id'     => ['required_if:target_type,sale', 'nullable', 'integer'],
            'purchase_order_id' => ['required_if:target_type,purchase', 'nullable', 'integer'],
            'amount'            => ['required', 'numeric', 'gt:0'],
            'payment_date'      => ['required', 'date'],
            'payment_method'    => ['required', 'string', 'in:cash,bank_transfer,check,card,mobile_banking,other'],
            'account_code'      => ['required', 'string'],
            'reference'         => ['nullable', 'string', 'max:200'],
        ]);

        return $this->onceForThisSubmission('inventory.payment.create', function () use ($validated) {
            $payload = [
                'date'         => $validated['payment_date'],
                'amount'       => $validated['amount'],
                'method'       => $validated['payment_method'],
                'account_code' => $validated['account_code'],
                'reference'    => $validated['reference'],
            ];

            $accounting = app(\Centrex\Accounting\Accounting::class);

            if ($validated['target_type'] === 'sale') {
                $saleOrder = SaleOrder::findOrFail($validated['sale_order_id']);
                $invoice = \Centrex\Accounting\Models\Invoice::findOrFail($saleOrder->accounting_invoice_id);
                $accounting->recordInvoicePayment($invoice, $payload);
            } else {
                $purchaseOrder = PurchaseOrder::findOrFail($validated['purchase_order_id']);
                $bill = \Centrex\Accounting\Models\Bill::findOrFail($purchaseOrder->accounting_bill_id);
                $accounting->recordBillPayment($bill, $payload);
            }

            $this->dispatch('notify', type: 'success', message: 'Payment recorded.');

            return redirect()->route('inventory.payments.index');
        });
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.payment-form', [
            'saleOrders' => SaleOrder::query()
                ->whereNotNull('accounting_invoice_id')
                ->where('due_amount', '>', 0)
                ->orderByDesc('id')
                ->limit(200)
                ->get(['id', 'so_number', 'due_amount']),
            'purchaseOrders' => PurchaseOrder::query()
                ->whereNotNull('accounting_bill_id')
                ->where('due_amount', '>', 0)
                ->orderByDesc('id')
                ->limit(200)
                ->get(['id', 'po_number', 'due_amount']),
        ]);
    }
}

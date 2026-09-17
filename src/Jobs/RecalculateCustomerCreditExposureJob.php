<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Jobs;

use Centrex\Inventory\Facades\Inventory;
use Centrex\Inventory\Models\Customer;
use Centrex\Inventory\Support\ErpIntegration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldBeUnique, ShouldQueue};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Log;

/**
 * Resyncs a customer's SaleOrder.due_amount/paid_amount from their linked
 * accounting invoices, then logs the fresh credit exposure snapshot.
 *
 * Dispatch this after any payment affecting the customer, or on demand to
 * repair drift (e.g. after manual DB edits or a missed observer run).
 *
 * ShouldBeUnique: a single payment can trigger this twice in the same request —
 * once from InvoicePaymentObserver (Invoice::paid_amount dirty) and once from
 * PaymentMirrorObserver (the Payment row itself being saved) — since either can fire
 * without the other in edge cases (a payment synced in from QuickBooks, a future
 * write path). Both dispatches are otherwise redundant work against the same customer.
 */
class RecalculateCustomerCreditExposureJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 60;

    public function __construct(public readonly int $customerId) {}

    public function uniqueId(): string
    {
        return (string) $this->customerId;
    }

    public function handle(): void
    {
        if (!class_exists(\Centrex\Accounting\Models\Invoice::class)) {
            return;
        }

        $customer = Customer::find($this->customerId);

        if (!$customer) {
            return;
        }

        $saleOrders = $customer->saleOrders()
            ->whereNotNull('accounting_invoice_id')
            ->get(['id', 'accounting_invoice_id']);

        if ($saleOrders->isEmpty()) {
            return;
        }

        // Full models, not a lean column select: Invoice::$balance (used below via
        // resyncSaleOrderDueAmount()) is a computed accessor that queries the invoice's own
        // expenses()/creditMemos() relations, so it needs a real Invoice instance to call on.
        $invoices = \Centrex\Accounting\Models\Invoice::query()
            ->whereIn('id', $saleOrders->pluck('accounting_invoice_id'))
            ->get()
            ->keyBy('id');

        $erp = app(ErpIntegration::class);

        foreach ($saleOrders as $saleOrder) {
            $invoice = $invoices->get($saleOrder->accounting_invoice_id);

            if (!$invoice) {
                continue;
            }

            // Same formula InvoicePaymentObserver::updated() uses — total-paid_amount alone
            // ignores AR-reducing discounts and issued credit memos (see Invoice::$balance),
            // which previously let this job clobber a correct discount/credit-memo-adjusted
            // due_amount with an inflated one on every recalculation run.
            $erp->resyncSaleOrderDueAmount($saleOrder, $invoice);
        }

        $snapshot = Inventory::customerCreditSnapshot($this->customerId);

        Log::info('inventory.customer_credit_exposure_recalculated', [
            'customer_id'             => $this->customerId,
            'outstanding_exposure'    => $snapshot['outstanding_exposure'],
            'available_credit_amount' => $snapshot['available_credit_amount'],
            'is_over_limit'           => $snapshot['is_over_limit'],
        ]);
    }
}

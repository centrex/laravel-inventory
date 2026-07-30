<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Observers;

use Centrex\Accounting\Models\Invoice;
use Centrex\Inventory\Models\SaleOrder;
use Centrex\Inventory\Support\ErpIntegration;

/**
 * Invoice::$balance already nets out AR-reducing discounts (accounts 6130-6133) the moment an
 * Expense against one of those accounts is recorded (see Invoice::getBalanceAttribute()), but
 * recording a discount never saves the Invoice itself, so InvoicePaymentObserver — which only
 * reacts to Invoice::paid_amount being dirty — never fires. Without this, a sale order's
 * due_amount stays stale until some unrelated action (a payment, a document resync) happens to
 * recompute it.
 */
class InvoiceDiscountObserver
{
    public function saved(object $expense): void
    {
        if ($expense->chargeable_type !== Invoice::class) {
            return;
        }

        $account = $expense->account;

        if (!$account || !in_array($account->code, Invoice::AR_REDUCING_ACCOUNT_CODES, true)) {
            return;
        }

        $invoice = Invoice::find($expense->chargeable_id);

        if (!$invoice) {
            return;
        }

        $saleOrders = SaleOrder::where('accounting_invoice_id', $invoice->id)->get();

        if ($saleOrders->isEmpty()) {
            return;
        }

        $erpIntegration = app(ErpIntegration::class);

        foreach ($saleOrders as $saleOrder) {
            $erpIntegration->resyncSaleOrderDueAmount($saleOrder, $invoice);
        }
    }
}

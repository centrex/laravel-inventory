<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Observers;

use Centrex\Inventory\Jobs\RecalculateCustomerCreditExposureJob;
use Centrex\Inventory\Models\SaleOrder;
use Centrex\Inventory\Support\ErpIntegration;

class InvoicePaymentObserver
{
    public function updated(object $invoice): void
    {
        if (!$invoice->isDirty('paid_amount')) {
            return;
        }

        $saleOrders = SaleOrder::where('accounting_invoice_id', $invoice->id)->get(['id', 'customer_id', 'status']);

        if ($saleOrders->isEmpty()) {
            return;
        }

        // Delegate to ErpIntegration::resyncSaleOrderDueAmount() rather than recomputing
        // total-paid_amount here: that formula ignores AR-reducing discounts and issued
        // credit memos (see Invoice::$balance), so a payment recorded after a sale-return
        // credit memo silently overwrote the memo's due_amount reduction with a stale value.
        $erp = app(ErpIntegration::class);

        foreach ($saleOrders as $saleOrder) {
            $erp->resyncSaleOrderDueAmount($saleOrder, $invoice);
        }

        $saleOrders->pluck('customer_id')->unique()->each(
            fn (int $customerId) => RecalculateCustomerCreditExposureJob::dispatch($customerId),
        );
    }
}

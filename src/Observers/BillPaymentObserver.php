<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Observers;

use Centrex\Inventory\Jobs\RecalculateSupplierCreditExposureJob;
use Centrex\Inventory\Models\PurchaseOrder;
use Centrex\Inventory\Support\ErpIntegration;

class BillPaymentObserver
{
    public function updated(object $bill): void
    {
        if (!$bill->isDirty('paid_amount')) {
            return;
        }

        $purchaseOrders = PurchaseOrder::where('accounting_bill_id', $bill->id)->get(['id', 'supplier_id']);

        if ($purchaseOrders->isEmpty()) {
            return;
        }

        // Delegate to ErpIntegration::resyncPurchaseOrderDueAmount() rather than recomputing
        // total-paid_amount here: that formula ignores AP-reducing discounts (see Bill::$balance),
        // so a payment recorded after a purchase discount silently overwrote the discount's
        // due_amount reduction with a stale value (the same bug this mirrors on the sale side —
        // see InvoicePaymentObserver).
        $erp = app(ErpIntegration::class);

        foreach ($purchaseOrders as $purchaseOrder) {
            $erp->resyncPurchaseOrderDueAmount($purchaseOrder, $bill);
        }

        $purchaseOrders->pluck('supplier_id')->unique()->each(
            fn (int $supplierId) => RecalculateSupplierCreditExposureJob::dispatch($supplierId),
        );
    }
}

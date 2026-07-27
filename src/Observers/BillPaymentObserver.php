<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Observers;

use Centrex\Inventory\Jobs\RecalculateSupplierCreditExposureJob;
use Centrex\Inventory\Models\PurchaseOrder;

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

        // $bill->total/paid_amount are already in base currency (see
        // ErpIntegration::syncPurchaseOrderDocument()), same as PurchaseOrder::due_amount/paid_amount —
        // no rate conversion needed here (multiplying by exchange_rate again double-converts).
        $paid = round(max(0.0, (float) $bill->paid_amount), 4);
        $due = round(max(0.0, (float) $bill->total - (float) $bill->paid_amount), 4);

        foreach ($purchaseOrders as $purchaseOrder) {
            $purchaseOrder->updateQuietly([
                'paid_amount' => $paid,
                'due_amount'  => $due,
            ]);
        }

        $purchaseOrders->pluck('supplier_id')->unique()->each(
            fn (int $supplierId) => RecalculateSupplierCreditExposureJob::dispatch($supplierId),
        );
    }
}

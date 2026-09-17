<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Jobs;

use Centrex\Inventory\Facades\Inventory;
use Centrex\Inventory\Models\Supplier;
use Centrex\Inventory\Support\ErpIntegration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldBeUnique, ShouldQueue};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Log;

/**
 * Resyncs a supplier's PurchaseOrder.due_amount/paid_amount from their linked
 * accounting bills, then logs the fresh credit exposure snapshot.
 *
 * Dispatch this after any payment affecting the supplier, or on demand to
 * repair drift (e.g. after manual DB edits or a missed observer run).
 *
 * ShouldBeUnique: see RecalculateCustomerCreditExposureJob — BillPaymentObserver and
 * PaymentMirrorObserver can both dispatch this for the same underlying payment.
 */
class RecalculateSupplierCreditExposureJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 60;

    public function __construct(public readonly int $supplierId) {}

    public function uniqueId(): string
    {
        return (string) $this->supplierId;
    }

    public function handle(): void
    {
        if (!class_exists(\Centrex\Accounting\Models\Bill::class)) {
            return;
        }

        $supplier = Supplier::find($this->supplierId);

        if (!$supplier) {
            return;
        }

        $purchaseOrders = $supplier->purchaseOrders()
            ->whereNotNull('accounting_bill_id')
            ->get(['id', 'accounting_bill_id']);

        if ($purchaseOrders->isEmpty()) {
            return;
        }

        // Full models, not a lean column select: Bill::$balance (used below via
        // resyncPurchaseOrderDueAmount()) is a computed accessor that queries the bill's own
        // expenses() relation, so it needs a real Bill instance to call on.
        $bills = \Centrex\Accounting\Models\Bill::query()
            ->whereIn('id', $purchaseOrders->pluck('accounting_bill_id'))
            ->get()
            ->keyBy('id');

        $erp = app(ErpIntegration::class);

        foreach ($purchaseOrders as $purchaseOrder) {
            $bill = $bills->get($purchaseOrder->accounting_bill_id);

            if (!$bill) {
                continue;
            }

            // Same formula BillPaymentObserver::updated() uses — total-paid_amount alone
            // ignores AP-reducing discounts (see Bill::$balance), which previously let this
            // job clobber a correct discount-adjusted due_amount with an inflated one on
            // every recalculation run (mirrors the sale-side bug in
            // RecalculateCustomerCreditExposureJob).
            $erp->resyncPurchaseOrderDueAmount($purchaseOrder, $bill);
        }

        $snapshot = Inventory::supplierCreditSnapshot($this->supplierId);

        Log::info('inventory.supplier_credit_exposure_recalculated', [
            'supplier_id'             => $this->supplierId,
            'outstanding_exposure'    => $snapshot['outstanding_exposure'],
            'available_credit_amount' => $snapshot['available_credit_amount'],
            'is_over_limit'           => $snapshot['is_over_limit'],
        ]);
    }
}

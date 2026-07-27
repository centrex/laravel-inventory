<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Jobs;

use Centrex\Inventory\Facades\Inventory;
use Centrex\Inventory\Models\Supplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Log;

/**
 * Resyncs a supplier's PurchaseOrder.due_amount/paid_amount from their linked
 * accounting bills, then logs the fresh credit exposure snapshot.
 *
 * Dispatch this after any payment affecting the supplier, or on demand to
 * repair drift (e.g. after manual DB edits or a missed observer run).
 */
class RecalculateSupplierCreditExposureJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $supplierId) {}

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

        $bills = \Centrex\Accounting\Models\Bill::query()
            ->whereIn('id', $purchaseOrders->pluck('accounting_bill_id'))
            ->get(['id', 'total', 'paid_amount', 'exchange_rate'])
            ->keyBy('id');

        foreach ($purchaseOrders as $purchaseOrder) {
            $bill = $bills->get($purchaseOrder->accounting_bill_id);

            if (!$bill) {
                continue;
            }

            $rate = (float) ($bill->exchange_rate ?? 1.0);

            $purchaseOrder->updateQuietly([
                'paid_amount' => round(max(0.0, (float) $bill->paid_amount * $rate), 4),
                'due_amount'  => round(max(0.0, ((float) $bill->total - (float) $bill->paid_amount) * $rate), 4),
            ]);
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

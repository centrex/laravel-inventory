<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Jobs;

use Centrex\Inventory\Models\{PurchaseOrder, StockReceipt};
use Centrex\Inventory\Support\ErpIntegration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};

/**
 * Reverses a voided GRN's journal entry, then re-syncs the linked PO's draft bill so its
 * grni_clearing_amount drops back down. See PostStockReceiptAccountingEntryJob (the mirror of
 * this job) for why both steps run together, in order, and why this must not run inline in the
 * request that voided the GRN.
 */
class VoidStockReceiptAccountingEntryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $stockReceiptId) {}

    public function handle(ErpIntegration $erp): void
    {
        $stockReceipt = StockReceipt::find($this->stockReceiptId);

        if (!$stockReceipt) {
            return;
        }

        $erp->voidStockReceipt($stockReceipt);

        if ($stockReceipt->purchase_order_id) {
            $purchaseOrder = PurchaseOrder::find($stockReceipt->purchase_order_id);

            if ($purchaseOrder) {
                $erp->syncPurchaseOrderDocument($purchaseOrder);
            }
        }
    }
}

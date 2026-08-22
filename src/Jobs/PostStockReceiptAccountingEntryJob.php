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
 * Posts the journal entry that capitalizes a posted GRN's received goods (DR Inventory Asset /
 * CR Goods Received Not Invoiced), then re-syncs the linked PO's draft bill so its
 * grni_clearing_amount reflects this receipt. Both steps run here, in this order, as one job
 * — not split across two — because the bill resync depends on the journal entry already
 * existing; queuing them as two independent jobs wouldn't guarantee that order.
 *
 * Out-of-band from the request that posted the GRN for the same reason as
 * SyncSaleOrderAccountingDocumentJob: accountId() lookups here can throw (missing/inactive GL
 * account), and previously did so inline right after the GRN's own stock movements/WAC update
 * had already committed — turning a fully-posted GRN into a 500 for the user.
 */
class PostStockReceiptAccountingEntryJob implements ShouldQueue
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

        $erp->postStockReceipt($stockReceipt);

        if ($stockReceipt->purchase_order_id) {
            $purchaseOrder = PurchaseOrder::find($stockReceipt->purchase_order_id);

            if ($purchaseOrder) {
                $erp->syncPurchaseOrderDocument($purchaseOrder);
            }
        }
    }
}

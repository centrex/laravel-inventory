<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Jobs;

use Centrex\Inventory\Models\PurchaseOrder;
use Centrex\Inventory\Support\ErpIntegration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};

/**
 * Creates/updates the accounting Bill linked to a purchase order, out-of-band from the
 * request that created/confirmed it. Mirrors SyncSaleOrderAccountingDocumentJob — see that
 * class for why this must not run inline in the request/response cycle.
 */
class SyncPurchaseOrderAccountingDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $purchaseOrderId) {}

    public function handle(ErpIntegration $erp): void
    {
        $purchaseOrder = PurchaseOrder::find($this->purchaseOrderId);

        if (!$purchaseOrder) {
            return;
        }

        $erp->syncPurchaseOrderDocument($purchaseOrder);
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Jobs;

use Centrex\Inventory\Models\SaleOrder;
use Centrex\Inventory\Support\ErpIntegration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};

/**
 * Creates/updates the accounting Invoice linked to a sale order, out-of-band from the request
 * that created/edited it. syncSaleOrderDocument() previously ran inline right after the sale
 * order's own DB::transaction() committed — any exception it threw (e.g. a missing/inactive GL
 * account) or any slowness in it turned an already-persisted sale order into a 500 or a
 * request-timeout response, while the order itself sat in the database the whole time. Users
 * retrying after that error is what produced real duplicate sale orders. Running this as a
 * queued job means nothing the accounting sync does can affect the create/edit response at all.
 */
class SyncSaleOrderAccountingDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $saleOrderId) {}

    public function handle(ErpIntegration $erp): void
    {
        $saleOrder = SaleOrder::find($this->saleOrderId);

        if (!$saleOrder) {
            return;
        }

        $erp->syncSaleOrderDocument($saleOrder);
    }
}

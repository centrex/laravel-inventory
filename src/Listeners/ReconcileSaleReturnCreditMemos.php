<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Listeners;

use Centrex\Accounting\Events\InvoicePosted;
use Centrex\Inventory\Support\ErpIntegration;

/**
 * Sale returns posted while their sale order's invoice was still draft skip their AR credit
 * memo at the time (see ErpIntegration::postSaleReturn()'s docblock) — this catches them up
 * the moment that invoice is actually posted. Deliberately synchronous (not ShouldQueue):
 * this is a handful of lightweight DB queries, not worth deferring, and running inline means
 * the credit memo is already there by the time the "post invoice" request finishes rather
 * than depending on a queue worker being up.
 */
class ReconcileSaleReturnCreditMemos
{
    public function handle(InvoicePosted $event): void
    {
        app(ErpIntegration::class)->reconcileSaleReturnsForInvoice($event->invoice);
    }
}

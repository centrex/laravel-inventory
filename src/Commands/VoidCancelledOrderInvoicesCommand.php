<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Commands;

use Centrex\Inventory\Models\SaleOrder;
use Centrex\Inventory\Support\ErpIntegration;
use Illuminate\Console\Command;

/**
 * One-off backfill for sale orders that were cancelled before cancelSaleOrder() started
 * voiding their linked invoice — finds cancelled orders whose invoice is still not void
 * and runs the same ErpIntegration::voidSaleOrderInvoice() logic against them.
 */
class VoidCancelledOrderInvoicesCommand extends Command
{
    public $signature = 'inventory:void-cancelled-order-invoices
        {--dry-run : List affected orders without voiding anything}';

    public $description = 'Void the linked invoice (and its journal entry, if posted) for already-cancelled sale orders whose invoice was never voided.';

    public function handle(ErpIntegration $erp): int
    {
        if (!$erp->enabled()) {
            $this->error('Accounting integration is disabled (INVENTORY_ACCOUNTING_ENABLED). Nothing to do.');

            return self::FAILURE;
        }

        $invoiceClass = \Centrex\Accounting\Models\Invoice::class;
        $dryRun = (bool) $this->option('dry-run');

        $orders = SaleOrder::query()
            ->where('status', 'cancelled')
            ->whereNotNull('accounting_invoice_id')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('No cancelled sale orders with a linked invoice found.');

            return self::SUCCESS;
        }

        $voided = 0;
        $skippedPaid = 0;
        $skippedAlreadyVoid = 0;

        foreach ($orders as $so) {
            $invoice = $invoiceClass::find($so->accounting_invoice_id);

            if (!$invoice) {
                continue;
            }

            $status = $invoice->status instanceof \BackedEnum ? $invoice->status->value : (string) $invoice->status;

            if ($status === 'void') {
                $skippedAlreadyVoid++;

                continue;
            }

            if ((float) $invoice->paid_amount > 0) {
                $this->warn("Skipping {$so->so_number}: invoice {$invoice->invoice_number} has a payment recorded (paid={$invoice->paid_amount}) — needs a manual decision.");
                $skippedPaid++;

                continue;
            }

            $this->line("{$so->so_number}: voiding invoice {$invoice->invoice_number} (status={$status}).");

            if (!$dryRun) {
                $erp->voidSaleOrderInvoice($so);
            }

            $voided++;
        }

        $this->info(($dryRun ? '[dry run] Would void' : 'Voided') . " {$voided} invoice(s). Skipped {$skippedAlreadyVoid} already void, {$skippedPaid} with payments recorded.");

        return self::SUCCESS;
    }
}

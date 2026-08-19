<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Commands;

use Centrex\Accounting\Models\Invoice;
use Centrex\Inventory\Models\SaleOrder;
use Centrex\Inventory\Support\ErpIntegration;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Detects sale orders whose SaleOrder::$due_amount/$paid_amount has drifted from what its
 * linked accounting Invoice actually shows (Invoice::$balance — which nets out AR-reducing
 * discounts and issued credit memos). The two are supposed to be kept in sync by
 * InvoicePaymentObserver, InvoiceDiscountObserver, and ErpIntegration::resyncSaleOrderDueAmount()
 * whenever a payment, discount, or sale-return credit memo posts — but a payment recorded
 * *before* that resync logic covered credit memos (or any future gap of the same shape: a
 * write to Invoice that doesn't route through one of those hooks) leaves SaleOrder::$due_amount
 * stale until something else happens to touch it.
 *
 * Usage:
 *   php artisan inventory:check-due-amounts             # report only
 *   php artisan inventory:check-due-amounts --fix        # also resync mismatched orders
 */
class CheckDueAmountDiscrepancyCommand extends Command
{
    public $signature = 'inventory:check-due-amounts
        {--fix : Resync SaleOrder::due_amount/paid_amount to match the linked Invoice}';

    public $description = 'Find sale orders whose due_amount/paid_amount disagrees with their linked accounting Invoice, and optionally fix them.';

    public function handle(ErpIntegration $erp): int
    {
        if (!$erp->enabled()) {
            $this->error('Accounting integration is disabled (INVENTORY_ACCOUNTING_ENABLED). Nothing to do.');

            return self::FAILURE;
        }

        $tolerance = (float) config('accounting.rounding_tolerance', 0.01);
        $fix = (bool) $this->option('fix');

        $saleOrders = SaleOrder::query()
            ->whereNotNull('accounting_invoice_id')
            ->get(['id', 'so_number', 'customer_id', 'status', 'accounting_invoice_id', 'due_amount', 'paid_amount']);

        if ($saleOrders->isEmpty()) {
            $this->info('No sale orders with a linked invoice found.');

            return self::SUCCESS;
        }

        /** @var Collection<int, Invoice> $invoices */
        $invoices = Invoice::query()
            ->whereIn('id', $saleOrders->pluck('accounting_invoice_id')->unique())
            ->get()
            ->keyBy('id');

        $orphaned = 0;
        $mismatched = 0;
        $fixed = 0;

        foreach ($saleOrders as $so) {
            $invoice = $invoices->get($so->accounting_invoice_id);

            if (!$invoice) {
                $this->warn("{$so->so_number}: linked invoice #{$so->accounting_invoice_id} no longer exists.");
                $orphaned++;

                continue;
            }

            $expectedDue = round((float) $invoice->balance, 4);
            $expectedPaid = round(max(0.0, (float) $invoice->paid_amount), 4);

            $dueDrift = abs((float) $so->due_amount - $expectedDue);
            $paidDrift = abs((float) $so->paid_amount - $expectedPaid);

            if ($dueDrift <= $tolerance && $paidDrift <= $tolerance) {
                continue;
            }

            $mismatched++;

            $this->line(sprintf(
                '%s (invoice %s): due_amount=%.2f expected=%.2f | paid_amount=%.2f expected=%.2f',
                $so->so_number,
                $invoice->invoice_number,
                (float) $so->due_amount,
                $expectedDue,
                (float) $so->paid_amount,
                $expectedPaid,
            ));

            if ($fix) {
                $erp->resyncSaleOrderDueAmount($so, $invoice);
                $fixed++;
            }
        }

        if ($mismatched === 0 && $orphaned === 0) {
            $this->info('No due-amount discrepancies found.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info($fix
            ? "Fixed {$fixed} of {$mismatched} mismatched sale order(s). {$orphaned} orphaned (no linked invoice)."
            : "Found {$mismatched} mismatched sale order(s). {$orphaned} orphaned (no linked invoice). Re-run with --fix to resync.");

        return self::SUCCESS;
    }
}

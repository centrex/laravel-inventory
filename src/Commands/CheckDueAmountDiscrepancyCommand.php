<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Commands;

use Centrex\Accounting\Models\Invoice;
use Centrex\Inventory\Mail\DueAmountDiscrepancyReportMail;
use Centrex\Inventory\Models\SaleOrder;
use Centrex\Inventory\Support\ErpIntegration;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

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
 *   php artisan inventory:check-due-amounts --fix --dry-run  # report what --fix would resync, without writing
 *   php artisan inventory:check-due-amounts --email=ops@example.com --email=cfo@example.com
 */
class CheckDueAmountDiscrepancyCommand extends Command
{
    public $signature = 'inventory:check-due-amounts
        {--fix : Resync SaleOrder::due_amount/paid_amount to match the linked Invoice}
        {--dry-run : List mismatched orders without resyncing anything, even if --fix is also passed}
        {--email=* : Email address(es) to send the report to, in addition to console output}';

    public $description = 'Find sale orders whose due_amount/paid_amount disagrees with their linked accounting Invoice, and optionally fix them.';

    public function handle(ErpIntegration $erp): int
    {
        if (!$erp->enabled()) {
            $this->error('Accounting integration is disabled (INVENTORY_ACCOUNTING_ENABLED). Nothing to do.');

            return self::FAILURE;
        }

        $tolerance = (float) config('accounting.rounding_tolerance', 0.01);
        $dryRun = (bool) $this->option('dry-run');
        $fix = (bool) $this->option('fix') && !$dryRun;
        /** @var array<int, string> $recipients */
        $recipients = (array) $this->option('email');
        $reportLines = [];

        $saleOrders = SaleOrder::query()
            ->whereNotNull('accounting_invoice_id')
            ->get(['id', 'so_number', 'customer_id', 'status', 'accounting_invoice_id', 'due_amount', 'paid_amount']);

        if ($saleOrders->isEmpty()) {
            $this->info('No sale orders with a linked invoice found.');
            $this->sendReport($recipients, 'No sale orders with a linked invoice found.', []);

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
                $reportLines[] = "{$so->so_number}: linked invoice #{$so->accounting_invoice_id} no longer exists.";
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

            $line = sprintf(
                '%s (invoice %s): due_amount=%.2f expected=%.2f | paid_amount=%.2f expected=%.2f',
                $so->so_number,
                $invoice->invoice_number,
                (float) $so->due_amount,
                $expectedDue,
                (float) $so->paid_amount,
                $expectedPaid,
            );
            $this->line($line);
            $reportLines[] = $line;

            if ($fix) {
                $erp->resyncSaleOrderDueAmount($so, $invoice);
                $fixed++;
            }
        }

        if ($mismatched === 0 && $orphaned === 0) {
            $this->info('No due-amount discrepancies found.');
            $this->sendReport($recipients, 'No due-amount discrepancies found.', []);

            return self::SUCCESS;
        }

        $this->newLine();

        if ($fix) {
            $summary = "Fixed {$fixed} of {$mismatched} mismatched sale order(s). {$orphaned} orphaned (no linked invoice).";
        } elseif ($dryRun && $this->option('fix')) {
            $summary = "[dry run] Would fix {$mismatched} mismatched sale order(s). {$orphaned} orphaned (no linked invoice).";
        } else {
            $summary = "Found {$mismatched} mismatched sale order(s). {$orphaned} orphaned (no linked invoice). Re-run with --fix to resync.";
        }

        $this->info($summary);
        $this->sendReport($recipients, $summary, $reportLines);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $recipients
     * @param  array<int, string>  $lines
     */
    protected function sendReport(array $recipients, string $summary, array $lines): void
    {
        if ($recipients === []) {
            return;
        }

        $body = $summary;

        if ($lines !== []) {
            $body .= "\n\n" . implode("\n", $lines);
        }

        Mail::to($recipients)->send(new DueAmountDiscrepancyReportMail($body));
    }
}

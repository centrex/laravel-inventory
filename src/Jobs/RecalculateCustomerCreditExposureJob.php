<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Jobs;

use Centrex\Inventory\Facades\Inventory;
use Centrex\Inventory\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Log;

/**
 * Resyncs a customer's SaleOrder.due_amount/paid_amount from their linked
 * accounting invoices, then logs the fresh credit exposure snapshot.
 *
 * Dispatch this after any payment affecting the customer, or on demand to
 * repair drift (e.g. after manual DB edits or a missed observer run).
 */
class RecalculateCustomerCreditExposureJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $customerId) {}

    public function handle(): void
    {
        if (!class_exists(\Centrex\Accounting\Models\Invoice::class)) {
            return;
        }

        $customer = Customer::find($this->customerId);

        if (!$customer) {
            return;
        }

        $saleOrders = $customer->saleOrders()
            ->whereNotNull('accounting_invoice_id')
            ->get(['id', 'accounting_invoice_id']);

        if ($saleOrders->isEmpty()) {
            return;
        }

        $invoices = \Centrex\Accounting\Models\Invoice::query()
            ->whereIn('id', $saleOrders->pluck('accounting_invoice_id'))
            ->get(['id', 'total', 'paid_amount', 'exchange_rate'])
            ->keyBy('id');

        foreach ($saleOrders as $saleOrder) {
            $invoice = $invoices->get($saleOrder->accounting_invoice_id);

            if (!$invoice) {
                continue;
            }

            $rate = (float) ($invoice->exchange_rate ?? 1.0);

            $saleOrder->updateQuietly([
                'paid_amount' => round(max(0.0, (float) $invoice->paid_amount * $rate), 4),
                'due_amount'  => round(max(0.0, ((float) $invoice->total - (float) $invoice->paid_amount) * $rate), 4),
            ]);
        }

        $snapshot = Inventory::customerCreditSnapshot($this->customerId);

        Log::info('inventory.customer_credit_exposure_recalculated', [
            'customer_id'             => $this->customerId,
            'outstanding_exposure'    => $snapshot['outstanding_exposure'],
            'available_credit_amount' => $snapshot['available_credit_amount'],
            'is_over_limit'           => $snapshot['is_over_limit'],
        ]);
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Observers;

use Centrex\Inventory\Jobs\RecalculateCustomerCreditExposureJob;
use Centrex\Inventory\Models\SaleOrder;

class InvoicePaymentObserver
{
    public function updated(object $invoice): void
    {
        if (!$invoice->isDirty('paid_amount')) {
            return;
        }

        $saleOrders = SaleOrder::where('accounting_invoice_id', $invoice->id)->get(['id', 'customer_id']);

        if ($saleOrders->isEmpty()) {
            return;
        }

        $rate = (float) ($invoice->exchange_rate ?? 1.0);
        $paid = round(max(0.0, (float) $invoice->paid_amount * $rate), 4);
        $due = round(max(0.0, ((float) $invoice->total - (float) $invoice->paid_amount) * $rate), 4);

        foreach ($saleOrders as $saleOrder) {
            $saleOrder->updateQuietly([
                'paid_amount' => $paid,
                'due_amount'  => $due,
            ]);
        }

        $saleOrders->pluck('customer_id')->unique()->each(
            fn (int $customerId) => RecalculateCustomerCreditExposureJob::dispatch($customerId),
        );
    }
}

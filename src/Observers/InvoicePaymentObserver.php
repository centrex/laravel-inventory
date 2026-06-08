<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Observers;

use Centrex\Inventory\Models\SaleOrder;

class InvoicePaymentObserver
{
    public function updated(object $invoice): void
    {
        if (!$invoice->isDirty('paid_amount')) {
            return;
        }

        $so = SaleOrder::where('accounting_invoice_id', $invoice->id)->first();

        if (!$so) {
            return;
        }

        $rate = (float) ($invoice->exchange_rate ?? 1.0);
        $paid = round(max(0.0, (float) $invoice->paid_amount * $rate), 4);
        $due = round(max(0.0, ((float) $invoice->total - (float) $invoice->paid_amount) * $rate), 4);

        $so->updateQuietly([
            'paid_amount' => $paid,
            'due_amount'  => $due,
        ]);
    }
}

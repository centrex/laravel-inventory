<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DueAmountDiscrepancyReportMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public string $body) {}

    public function build(): self
    {
        return $this
            ->subject('Inventory due-amount discrepancy report')
            ->text('inventory::mail.due-amount-discrepancy-report');
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Observers;

use Centrex\Accounting\Exceptions\InvalidStatusTransitionException;
use Centrex\Inventory\Enums\SaleOrderStatus;
use Centrex\Inventory\Jobs\RecalculateCustomerCreditExposureJob;
use Centrex\Inventory\Models\SaleOrder;
use Centrex\Inventory\Support\ErpIntegration;

class InvoicePaymentObserver
{
    /** Sale order must have something delivered before its invoice can take a payment. */
    private const PAYABLE_STATUSES = [
        SaleOrderStatus::PARTIAL->value,
        SaleOrderStatus::FULFILLED->value,
        SaleOrderStatus::COMPLETED->value,
    ];

    /**
     * Vetoes a payment landing on an invoice whose sale order hasn't shipped anything yet
     * (draft/confirmed/processing/shipped) or is no longer active (cancelled/returned) —
     * thrown from `updating` so it aborts recordInvoicePayment()'s transaction before the
     * paid_amount write, rather than updated()'s after-the-fact recalculation.
     */
    public function updating(object $invoice): void
    {
        if (!$invoice->isDirty('paid_amount')) {
            return;
        }

        $blocked = SaleOrder::where('accounting_invoice_id', $invoice->id)
            ->whereNotIn('status', self::PAYABLE_STATUSES)
            ->exists();

        if ($blocked) {
            throw InvalidStatusTransitionException::make('SaleOrder', 'unfulfilled', 'payment');
        }
    }

    public function updated(object $invoice): void
    {
        if (!$invoice->isDirty('paid_amount')) {
            return;
        }

        $saleOrders = SaleOrder::where('accounting_invoice_id', $invoice->id)->get(['id', 'customer_id', 'status']);

        if ($saleOrders->isEmpty()) {
            return;
        }

        // Delegate to ErpIntegration::resyncSaleOrderDueAmount() rather than recomputing
        // total-paid_amount here: that formula ignores AR-reducing discounts and issued
        // credit memos (see Invoice::$balance), so a payment recorded after a sale-return
        // credit memo silently overwrote the memo's due_amount reduction with a stale value.
        $erp = app(ErpIntegration::class);

        foreach ($saleOrders as $saleOrder) {
            $erp->resyncSaleOrderDueAmount($saleOrder, $invoice);
        }

        $saleOrders->pluck('customer_id')->unique()->each(
            fn (int $customerId) => RecalculateCustomerCreditExposureJob::dispatch($customerId),
        );
    }
}

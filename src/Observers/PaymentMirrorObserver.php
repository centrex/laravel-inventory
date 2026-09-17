<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Observers;

use Centrex\Inventory\Jobs\{RecalculateCustomerCreditExposureJob, RecalculateSupplierCreditExposureJob};
use Centrex\Inventory\Models\{Payment as InventoryPayment, PurchaseOrder, SaleOrder};

/**
 * Mirrors laravel-accounting's Payment (invoice + bill payments) into inv_payments, so
 * inventory-side reporting/UI has a local read-model instead of depending on accounting's
 * own tables at query time. Payments are always created via
 * Accounting::recordInvoicePayment()/recordBillPayment() — this observer never originates data.
 *
 * Also dispatches the credit-exposure recalculation jobs directly off the Payment row itself,
 * rather than relying solely on InvoicePaymentObserver/BillPaymentObserver's Invoice/Bill
 * paid_amount dirty-check — that check only fires for the two documented facade methods; a
 * Payment created through another path (e.g. a future QuickBooks payment sync) would otherwise
 * never trigger a recalculation. The jobs are ShouldBeUnique, so the redundant dispatch when
 * both triggers fire for the same payment is a no-op, not double work.
 */
class PaymentMirrorObserver
{
    public function saved(object $payment): void
    {
        $context = $this->resolveContext($payment);

        InventoryPayment::withTrashed()->updateOrCreate(
            ['accounting_payment_id' => $payment->id],
            [
                ...$context,
                'payable_type'   => $payment->payable_type,
                'payable_id'     => $payment->payable_id,
                'payment_number' => $payment->payment_number,
                'payment_date'   => $payment->payment_date,
                'amount'         => $payment->amount,
                'payment_method' => $payment->payment_method,
                'reference'      => $payment->reference,
                'notes'          => $payment->notes,
                'deleted_at'     => null,
            ],
        );

        $this->dispatchExposureRecalculation($context);
    }

    public function deleted(object $payment): void
    {
        InventoryPayment::where('accounting_payment_id', $payment->id)->delete();

        $this->dispatchExposureRecalculation($this->resolveContext($payment));
    }

    public function restored(object $payment): void
    {
        InventoryPayment::withTrashed()->where('accounting_payment_id', $payment->id)->restore();

        $this->dispatchExposureRecalculation($this->resolveContext($payment));
    }

    /** @param array<string, mixed> $context */
    private function dispatchExposureRecalculation(array $context): void
    {
        if (is_int($context['customer_id'])) {
            RecalculateCustomerCreditExposureJob::dispatch($context['customer_id']);
        }

        if (is_int($context['supplier_id'])) {
            RecalculateSupplierCreditExposureJob::dispatch($context['supplier_id']);
        }
    }

    /** @return array<string, mixed> */
    private function resolveContext(object $payment): array
    {
        if ($payment->payable_type === \Centrex\Accounting\Models\Bill::class) {
            $purchaseOrder = PurchaseOrder::where('accounting_bill_id', $payment->payable_id)->first();

            return [
                'direction'         => 'purchase',
                'purchase_order_id' => $purchaseOrder?->id,
                'supplier_id'       => $purchaseOrder?->supplier_id,
                'warehouse_id'      => $purchaseOrder?->warehouse_id,
                'sale_order_id'     => null,
                'customer_id'       => null,
                'currency'          => $purchaseOrder?->currency,
            ];
        }

        $saleOrder = SaleOrder::where('accounting_invoice_id', $payment->payable_id)->first();

        return [
            'direction'         => 'sale',
            'sale_order_id'     => $saleOrder?->id,
            'customer_id'       => $saleOrder?->customer_id,
            'warehouse_id'      => $saleOrder?->warehouse_id,
            'purchase_order_id' => null,
            'supplier_id'       => null,
            'currency'          => $saleOrder?->currency,
        ];
    }
}

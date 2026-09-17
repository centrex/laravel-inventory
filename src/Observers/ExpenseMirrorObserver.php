<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Observers;

use Centrex\Inventory\Models\{Expense as InventoryExpense, PurchaseOrder, SaleOrder};

/**
 * Mirrors laravel-accounting's Expense (invoice/bill charges & discounts, and standalone
 * expenses) into inv_expenses — same reasoning as PaymentMirrorObserver. Expenses are always
 * created via Accounting::postExpense() (or accounting's own charge/discount flows); this
 * observer never originates data.
 *
 * Registered alongside InvoiceDiscountObserver on the same accounting Expense model —
 * Eloquent supports multiple observers per model, each independently invoked.
 */
class ExpenseMirrorObserver
{
    public function saved(object $expense): void
    {
        $context = $this->resolveContext($expense);
        $account = $expense->account;

        InventoryExpense::withTrashed()->updateOrCreate(
            ['accounting_expense_id' => $expense->id],
            [
                ...$context,
                'chargeable_type'       => $expense->chargeable_type,
                'chargeable_id'         => $expense->chargeable_id,
                'expense_number'        => $expense->expense_number,
                'accounting_account_id' => $expense->account_id,
                'account_code'          => $account?->code,
                'account_name'          => $account?->name,
                'expense_date'          => $expense->expense_date,
                'due_date'              => $expense->due_date,
                'subtotal'              => $expense->subtotal ?? 0,
                'tax_amount'            => $expense->tax_amount ?? 0,
                'total'                 => $expense->total ?? 0,
                'paid_amount'           => $expense->paid_amount ?? 0,
                'currency'              => $expense->currency,
                'exchange_rate'         => $expense->exchange_rate ?? 1,
                'status'                => $expense->status,
                'payment_method'        => $expense->payment_method,
                'reference'             => $expense->reference,
                'vendor_name'           => $expense->vendor_name,
                'notes'                 => $expense->notes,
                'deleted_at'            => null,
            ],
        );
    }

    public function deleted(object $expense): void
    {
        InventoryExpense::where('accounting_expense_id', $expense->id)->delete();
    }

    public function restored(object $expense): void
    {
        InventoryExpense::withTrashed()->where('accounting_expense_id', $expense->id)->restore();
    }

    /** @return array<string, mixed> */
    private function resolveContext(object $expense): array
    {
        if ($expense->chargeable_type === \Centrex\Accounting\Models\Bill::class) {
            $purchaseOrder = PurchaseOrder::where('accounting_bill_id', $expense->chargeable_id)->first();

            return [
                'direction'         => 'purchase',
                'purchase_order_id' => $purchaseOrder?->id,
                'supplier_id'       => $purchaseOrder?->supplier_id,
                'warehouse_id'      => $purchaseOrder?->warehouse_id,
                'sale_order_id'     => null,
                'customer_id'       => null,
            ];
        }

        if ($expense->chargeable_type === \Centrex\Accounting\Models\Invoice::class) {
            $saleOrder = SaleOrder::where('accounting_invoice_id', $expense->chargeable_id)->first();

            return [
                'direction'         => 'sale',
                'sale_order_id'     => $saleOrder?->id,
                'customer_id'       => $saleOrder?->customer_id,
                'warehouse_id'      => $saleOrder?->warehouse_id,
                'purchase_order_id' => null,
                'supplier_id'       => null,
            ];
        }

        return [
            'direction'         => 'standalone',
            'sale_order_id'     => null,
            'purchase_order_id' => null,
            'customer_id'       => null,
            'supplier_id'       => null,
            'warehouse_id'      => null,
        ];
    }
}

<?php

declare(strict_types = 1);

use Centrex\Accounting\Facades\Accounting;
use Centrex\Accounting\Models\{Account, Bill, Expense, Invoice};
use Centrex\Accounting\Models\{Customer as AccountingCustomer, Vendor as AccountingSupplier};
use Centrex\Inventory\Jobs\{RecalculateCustomerCreditExposureJob, RecalculateSupplierCreditExposureJob};
use Centrex\Inventory\Models\{Customer, PurchaseOrder, SaleOrder, Supplier, Warehouse};

it('does not clobber a discount-adjusted sale order due_amount when recalculating customer credit exposure', function (): void {
    Accounting::initializeChartOfAccounts();

    $warehouse = Warehouse::create(['code' => 'WH1', 'name' => 'Main WH', 'country_code' => 'BD', 'currency' => 'BDT', 'is_active' => true]);
    $customer = Customer::create(['code' => 'C001', 'name' => 'Acme', 'currency' => 'BDT']);
    $accountingCustomer = AccountingCustomer::create(['code' => 'AC001', 'name' => 'Acme', 'currency' => 'BDT']);

    $invoice = Invoice::create([
        'customer_id'    => $accountingCustomer->id,
        'invoice_number' => 'INV-0001',
        'invoice_date'   => today(),
        'due_date'       => today()->addDays(30),
        'currency'       => 'BDT',
        'subtotal'       => 1000,
        'tax_amount'     => 0,
        'total'          => 1000,
    ]);

    $saleOrder = SaleOrder::create([
        'so_number'             => 'SO-0001',
        'warehouse_id'          => $warehouse->id,
        'customer_id'           => $customer->id,
        'price_tier_code'       => 'BASE',
        'status'                => 'fulfilled',
        'currency'              => 'BDT',
        'exchange_rate'         => 1,
        'total_amount'          => 1000,
        'accounting_invoice_id' => $invoice->id,
        'paid_amount'           => 0,
        'due_amount'            => 1000,
    ]);

    // A 100 sales discount against the invoice — due should become 900 (InvoiceDiscountObserver
    // resyncs this immediately, verified separately by InvoiceDiscountSaleOrderSyncTest).
    $discountAccount = Account::where('code', '6130')->first();
    $arAccount = Account::where('code', '1200')->first();

    $expense = Expense::create([
        'chargeable_type' => Invoice::class,
        'chargeable_id'   => $invoice->id,
        'account_id'      => $discountAccount->id,
        'expense_date'    => today(),
        'subtotal'        => 100,
        'tax_amount'      => 0,
        'total'           => 100,
        'paid_amount'     => 100,
        'currency'        => 'BDT',
        'status'          => 'paid',
        'payment_method'  => 'cash',
        'reference'       => $invoice->invoice_number,
    ]);
    $entry = Accounting::createJournalEntry([
        'date'        => today(),
        'reference'   => $invoice->invoice_number,
        'type'        => 'general',
        'description' => 'Sales Discount test',
        'currency'    => 'BDT',
        'lines'       => [
            ['account_id' => $discountAccount->id, 'type' => 'debit', 'amount' => 100],
            ['account_id' => $arAccount->id, 'type' => 'credit', 'amount' => 100],
        ],
    ]);
    $entry->post();
    $expense->update(['journal_entry_id' => $entry->id]);

    // A 200 payment — InvoicePaymentObserver fires and (correctly) resyncs due_amount to
    // 1000 - 200 - 100 = 700. Before the fix, RecalculateCustomerCreditExposureJob (dispatched
    // by that same observer) would immediately overwrite it back to the naive 1000 - 200 = 800.
    $invoice->update(['paid_amount' => 200]);

    $saleOrder->refresh();
    expect((float) $saleOrder->due_amount)->toBe(700.0);

    // Explicitly re-running the recalculation job (e.g. a manual repair run) must stay
    // idempotent and not regress the discount-adjusted figure.
    RecalculateCustomerCreditExposureJob::dispatchSync($customer->id);
    $saleOrder->refresh();
    expect((float) $saleOrder->due_amount)->toBe(700.0);

    $snapshot = Centrex\Inventory\Facades\Inventory::customerCreditSnapshot($customer->id);
    expect((float) $snapshot['outstanding_exposure'])->toBe(700.0);
});

it('does not clobber a discount-adjusted purchase order due_amount when recalculating supplier credit exposure', function (): void {
    Accounting::initializeChartOfAccounts();

    $warehouse = Warehouse::create(['code' => 'WH2', 'name' => 'Second WH', 'country_code' => 'BD', 'currency' => 'BDT', 'is_active' => true]);
    $supplier = Supplier::create(['code' => 'S001', 'name' => 'Supplier Ltd', 'currency' => 'BDT']);
    $accountingSupplier = AccountingSupplier::create(['code' => 'AS001', 'name' => 'Supplier Ltd', 'currency' => 'BDT']);

    $bill = Bill::create([
        'vendor_id'  => $accountingSupplier->id,
        'bill_date'  => today(),
        'due_date'   => today()->addDays(30),
        'subtotal'   => 1000,
        'tax_amount' => 0,
        'total'      => 1000,
    ]);

    $purchaseOrder = PurchaseOrder::create([
        'po_number'          => 'PO-0001',
        'warehouse_id'       => $warehouse->id,
        'supplier_id'        => $supplier->id,
        'status'             => 'confirmed',
        'currency'           => 'BDT',
        'exchange_rate'      => 1,
        'total_amount'       => 1000,
        'accounting_bill_id' => $bill->id,
        'paid_amount'        => 0,
        'due_amount'         => 1000,
    ]);

    // A 100 purchase discount against the bill.
    $discountAccount = Account::where('code', '5500')->first();
    $apAccount = Account::where('code', '2000')->first();

    $expense = Expense::create([
        'chargeable_type' => Bill::class,
        'chargeable_id'   => $bill->id,
        'account_id'      => $discountAccount->id,
        'expense_date'    => today(),
        'subtotal'        => 100,
        'tax_amount'      => 0,
        'total'           => 100,
        'paid_amount'     => 100,
        'currency'        => 'BDT',
        'status'          => 'paid',
        'payment_method'  => 'cash',
        'reference'       => $bill->bill_number,
    ]);
    $entry = Accounting::createJournalEntry([
        'date'        => today(),
        'reference'   => $bill->bill_number,
        'type'        => 'general',
        'description' => 'Purchase Discount test',
        'currency'    => 'BDT',
        'lines'       => [
            ['account_id' => $apAccount->id, 'type' => 'debit', 'amount' => 100],
            ['account_id' => $discountAccount->id, 'type' => 'credit', 'amount' => 100],
        ],
    ]);
    $entry->post();
    $expense->update(['journal_entry_id' => $entry->id]);

    // A 200 payment — BillPaymentObserver fires and (correctly) resyncs due_amount to
    // 1000 - 200 - 100 = 700.
    $bill->update(['paid_amount' => 200]);

    $purchaseOrder->refresh();
    expect((float) $purchaseOrder->due_amount)->toBe(700.0);

    RecalculateSupplierCreditExposureJob::dispatchSync($supplier->id);
    $purchaseOrder->refresh();
    expect((float) $purchaseOrder->due_amount)->toBe(700.0);

    $snapshot = Centrex\Inventory\Facades\Inventory::supplierCreditSnapshot($supplier->id);
    expect((float) $snapshot['outstanding_exposure'])->toBe(700.0);
});

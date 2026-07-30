<?php

declare(strict_types = 1);

use Centrex\Accounting\Facades\Accounting;
use Centrex\Accounting\Models\{Account, Expense, Invoice};
use Centrex\Accounting\Models\Customer as AccountingCustomer;
use Centrex\Inventory\Models\{Customer, SaleOrder, Warehouse};

it('resyncs sale order due amount when a discount is recorded against the linked invoice', function (): void {
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
        'status'                => 'confirmed',
        'currency'              => 'BDT',
        'exchange_rate'         => 1,
        'total_amount'          => 1000,
        'accounting_invoice_id' => $invoice->id,
        'paid_amount'           => 0,
        'due_amount'            => 1000,
    ]);

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

    $saleOrder->refresh();

    expect((float) $saleOrder->due_amount)->toBe(900.0);
});

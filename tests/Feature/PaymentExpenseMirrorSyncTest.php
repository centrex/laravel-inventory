<?php

declare(strict_types = 1);

use Centrex\Accounting\Models\{Account, Bill, Expense as AccountingExpense, Invoice};
use Centrex\Accounting\Models\{Customer as AccountingCustomer, Vendor as AccountingVendor};
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Customer, Expense, Payment, Product, PurchaseOrder, SaleOrder, Warehouse, WarehouseProduct};
use Illuminate\Support\Facades\Route;

function seedAccountsForMirrorSyncTest(): void
{
    if (Account::where('code', '1000')->exists()) {
        return;
    }

    Account::create(['code' => '1000', 'name' => 'Cash', 'type' => 'asset', 'is_active' => true]);
    Account::create(['code' => '1200', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
    Account::create(['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'is_active' => true]);
    Account::create(['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability', 'is_active' => true]);
    Account::create(['code' => '2300', 'name' => 'Sales Tax Payable', 'type' => 'liability', 'is_active' => true]);
    Account::create(['code' => '4000', 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
    Account::create(['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'is_active' => true]);
    Account::create(['code' => '6900', 'name' => 'Office Supplies', 'type' => 'expense', 'is_active' => true]);
}

it('mirrors an invoice payment into inv_payments with sale-side linkage', function (): void {
    if (!class_exists(Account::class)) {
        $this->markTestSkipped('Accounting package is not available in this test environment.');
    }

    seedAccountsForMirrorSyncTest();

    $warehouse = Warehouse::create(['code' => 'W-MIR-1', 'name' => 'Mirror WH', 'country_code' => 'BD', 'currency' => 'BDT']);
    $customer = Customer::create(['code' => 'CUS-MIR-1', 'name' => 'Mirror Customer', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);
    $product = Product::create(['sku' => 'SKU-MIR-1', 'name' => 'Widget Mirror', 'unit' => 'pcs', 'is_stockable' => true]);
    WarehouseProduct::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'qty_on_hand' => 10, 'wac_amount' => 50]);

    $inventory = app(Inventory::class);
    $order = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customer->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [['product_id' => $product->id, 'qty_ordered' => 2, 'unit_price_local' => 300]],
    ]);
    $inventory->confirmSaleOrder($order->id);
    $inventory->reserveStock($order->id);
    $inventory->fulfillSaleOrder($order->id);

    $invoice = Invoice::findOrFail($order->fresh()->accounting_invoice_id);

    expect(Payment::where('customer_id', $customer->id)->count())->toBe(0);

    $accounting = app('accounting');
    $payment = $accounting->recordInvoicePayment($invoice->fresh(), [
        'date'         => today(),
        'amount'       => 100,
        'method'       => 'cash',
        'account_code' => '1000',
    ]);

    $mirror = Payment::where('accounting_payment_id', $payment->id)->first();

    expect($mirror)->not->toBeNull()
        ->and($mirror->direction)->toBe('sale')
        ->and($mirror->sale_order_id)->toBe($order->id)
        ->and($mirror->customer_id)->toBe($customer->id)
        ->and($mirror->warehouse_id)->toBe($warehouse->id)
        ->and((float) $mirror->amount)->toBe(100.0)
        ->and($mirror->payment_method)->toBe('cash');

    // Soft-deleting the accounting payment soft-deletes the mirror row too.
    $payment->delete();
    expect(Payment::where('accounting_payment_id', $payment->id)->exists())->toBeFalse();
    expect(Payment::withTrashed()->where('accounting_payment_id', $payment->id)->exists())->toBeTrue();
});

it('mirrors a bill payment into inv_payments with purchase-side linkage', function (): void {
    if (!class_exists(Account::class)) {
        $this->markTestSkipped('Accounting package is not available in this test environment.');
    }

    seedAccountsForMirrorSyncTest();

    $warehouse = Warehouse::create(['code' => 'W-MIR-2', 'name' => 'Mirror WH 2', 'country_code' => 'BD', 'currency' => 'BDT']);
    $supplier = Centrex\Inventory\Models\Supplier::create(['code' => 'SUP-MIR-1', 'name' => 'Mirror Supplier', 'currency' => 'BDT']);
    $accountingVendor = AccountingVendor::create(['code' => 'AV-MIR-1', 'name' => 'Mirror Supplier', 'currency' => 'BDT']);

    $bill = Bill::create([
        'vendor_id'  => $accountingVendor->id,
        'bill_date'  => today(),
        'due_date'   => today()->addDays(30),
        'subtotal'   => 1000,
        'tax_amount' => 0,
        'total'      => 1000,
    ]);

    $purchaseOrder = PurchaseOrder::create([
        'po_number'          => 'PO-MIR-1',
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

    $accounting = app('accounting');
    $accounting->postBill($bill);

    $payment = $accounting->recordBillPayment($bill->fresh(), [
        'date'         => today(),
        'amount'       => 400,
        'method'       => 'bank_transfer',
        'account_code' => '1000',
    ]);

    $mirror = Payment::where('accounting_payment_id', $payment->id)->first();

    expect($mirror)->not->toBeNull()
        ->and($mirror->direction)->toBe('purchase')
        ->and($mirror->purchase_order_id)->toBe($purchaseOrder->id)
        ->and($mirror->supplier_id)->toBe($supplier->id)
        ->and((float) $mirror->amount)->toBe(400.0)
        ->and($mirror->payment_method)->toBe('bank_transfer');
});

it('mirrors a standalone expense into inv_expenses with denormalized account info', function (): void {
    if (!class_exists(Account::class)) {
        $this->markTestSkipped('Accounting package is not available in this test environment.');
    }

    seedAccountsForMirrorSyncTest();
    $account = Account::where('code', '6900')->first();

    $expense = AccountingExpense::create([
        'account_id'     => $account->id,
        'expense_date'   => today(),
        'subtotal'       => 500,
        'tax_amount'     => 0,
        'total'          => 500,
        'currency'       => 'BDT',
        'payment_method' => 'cash',
    ]);

    app('accounting')->postExpense($expense);

    $mirror = Expense::where('accounting_expense_id', $expense->id)->first();

    expect($mirror)->not->toBeNull()
        ->and($mirror->direction)->toBe('standalone')
        ->and($mirror->account_code)->toBe('6900')
        ->and($mirror->account_name)->toBe('Office Supplies')
        ->and((float) $mirror->total)->toBe(500.0);
});

it('mirrors an invoice-chargeable expense into inv_expenses with sale-side linkage', function (): void {
    if (!class_exists(Account::class)) {
        $this->markTestSkipped('Accounting package is not available in this test environment.');
    }

    seedAccountsForMirrorSyncTest();

    $customer = Customer::create(['code' => 'CUS-MIR-2', 'name' => 'Mirror Customer 2', 'currency' => 'BDT']);
    $accountingCustomer = AccountingCustomer::create(['code' => 'AC-MIR-2', 'name' => 'Mirror Customer 2', 'currency' => 'BDT']);
    $warehouse = Warehouse::create(['code' => 'W-MIR-3', 'name' => 'Mirror WH 3', 'country_code' => 'BD', 'currency' => 'BDT']);

    $invoice = Invoice::create([
        'customer_id'    => $accountingCustomer->id,
        'invoice_number' => 'INV-MIR-1',
        'invoice_date'   => today(),
        'due_date'       => today()->addDays(30),
        'currency'       => 'BDT',
        'subtotal'       => 1000,
        'tax_amount'     => 0,
        'total'          => 1000,
    ]);

    $saleOrder = SaleOrder::create([
        'so_number'             => 'SO-MIR-1',
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

    $discountAccount = Account::create(['code' => '6130', 'name' => 'Sales Discount', 'type' => 'expense', 'is_active' => true]);

    $expense = AccountingExpense::create([
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

    $mirror = Expense::where('accounting_expense_id', $expense->id)->first();

    expect($mirror)->not->toBeNull()
        ->and($mirror->direction)->toBe('sale')
        ->and($mirror->sale_order_id)->toBe($saleOrder->id)
        ->and($mirror->customer_id)->toBe($customer->id);
});

it('does not register payments/expenses routes unless inventory.payments_ui.enabled is set', function (): void {
    expect(config('inventory.payments_ui.enabled', false))->toBeFalse();
    expect(Route::has('inventory.payments.index'))->toBeFalse();
    expect(Route::has('inventory.expenses.index'))->toBeFalse();
});

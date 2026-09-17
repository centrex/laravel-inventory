<?php

declare(strict_types = 1);

use Centrex\Accounting\Models\{Account, Bill};
use Centrex\Accounting\Models\Vendor as AccountingVendor;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Jobs\{RecalculateCustomerCreditExposureJob, RecalculateSupplierCreditExposureJob};
use Centrex\Inventory\Models\{Customer, Product, PurchaseOrder, Supplier, Warehouse, WarehouseProduct};
use Illuminate\Support\Facades\Queue;

function seedAccountsForQueuedPaymentTest(): void
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
}

it('implements ShouldQueue on both credit-exposure recalculation jobs', function (): void {
    expect(RecalculateCustomerCreditExposureJob::class)->toImplement(\Illuminate\Contracts\Queue\ShouldQueue::class);
    expect(RecalculateSupplierCreditExposureJob::class)->toImplement(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

it('queues exactly one customer credit-exposure recalculation per invoice payment', function (): void {
    if (!class_exists(Account::class)) {
        $this->markTestSkipped('Accounting package is not available in this test environment.');
    }

    seedAccountsForQueuedPaymentTest();
    Queue::fake();

    $warehouse = Warehouse::create(['code' => 'W-QJ-1', 'name' => 'Queue Job WH', 'country_code' => 'BD', 'currency' => 'BDT']);
    $customer = Customer::create(['code' => 'CUS-QJ-1', 'name' => 'Queue Job Customer', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);
    $product = Product::create(['sku' => 'SKU-QJ-1', 'name' => 'Widget QJ', 'unit' => 'pcs', 'is_stockable' => true]);
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

    $invoice = \Centrex\Accounting\Models\Invoice::findOrFail($order->fresh()->accounting_invoice_id);

    app('accounting')->recordInvoicePayment($invoice->fresh(), [
        'date'         => today(),
        'amount'       => 100,
        'method'       => 'cash',
        'account_code' => '1000',
    ]);

    // Both InvoicePaymentObserver (Invoice::paid_amount dirty) and PaymentMirrorObserver
    // (the Payment row itself) dispatch this job for the same payment; ShouldBeUnique should
    // collapse them into a single queued job rather than two.
    Queue::assertPushed(RecalculateCustomerCreditExposureJob::class, 1);
    Queue::assertPushed(fn (RecalculateCustomerCreditExposureJob $job): bool => $job->customerId === $customer->id);
});

it('queues exactly one supplier credit-exposure recalculation per bill payment', function (): void {
    if (!class_exists(Account::class)) {
        $this->markTestSkipped('Accounting package is not available in this test environment.');
    }

    seedAccountsForQueuedPaymentTest();

    $warehouse = Warehouse::create(['code' => 'W-QJ-2', 'name' => 'Queue Job WH 2', 'country_code' => 'BD', 'currency' => 'BDT']);
    $supplier = Supplier::create(['code' => 'SUP-QJ-1', 'name' => 'Queue Job Supplier', 'currency' => 'BDT']);
    $accountingVendor = AccountingVendor::create(['code' => 'AV-QJ-1', 'name' => 'Queue Job Supplier', 'currency' => 'BDT']);

    $bill = Bill::create([
        'vendor_id'  => $accountingVendor->id,
        'bill_date'  => today(),
        'due_date'   => today()->addDays(30),
        'subtotal'   => 1000,
        'tax_amount' => 0,
        'total'      => 1000,
    ]);

    PurchaseOrder::create([
        'po_number'          => 'PO-QJ-1',
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

    Queue::fake();

    $accounting->recordBillPayment($bill->fresh(), [
        'date'         => today(),
        'amount'       => 400,
        'method'       => 'bank_transfer',
        'account_code' => '1000',
    ]);

    Queue::assertPushed(RecalculateSupplierCreditExposureJob::class, 1);
    Queue::assertPushed(fn (RecalculateSupplierCreditExposureJob $job): bool => $job->supplierId === $supplier->id);
});

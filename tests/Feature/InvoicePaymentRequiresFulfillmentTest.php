<?php

declare(strict_types = 1);

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Customer, Product, Warehouse, WarehouseProduct};
use Centrex\Inventory\Support\ErpIntegration;

/**
 * A sale order's linked invoice must not take a payment until something has actually been
 * delivered (status partial/fulfilled/completed) — see InvoicePaymentObserver::updating().
 * Before this guard, a customer could pay for goods that hadn't shipped yet.
 */
function seedAccountsForPaymentGuardTest(): void
{
    $accountClass = 'Centrex\\Accounting\\Models\\Account';
    $accountClass::create(['code' => '1000', 'name' => 'Cash', 'type' => 'asset', 'is_active' => true]);
    $accountClass::create(['code' => '1200', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
    $accountClass::create(['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'is_active' => true]);
    $accountClass::create(['code' => '2300', 'name' => 'Sales Tax Payable', 'type' => 'liability', 'is_active' => true]);
    $accountClass::create(['code' => '4000', 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
    $accountClass::create(['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'is_active' => true]);
}

function createConfirmedSaleOrderForPaymentGuardTest(): Centrex\Inventory\Models\SaleOrder
{
    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create([
        'code'         => 'W-PG-1',
        'name'         => 'Payment Guard Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $customer = Customer::create(['code' => 'CUS-PG-1', 'name' => 'Payment Guard Customer', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);
    $product = Product::create(['sku' => 'SKU-PG-1', 'name' => 'Widget PG', 'unit' => 'pcs', 'is_stockable' => true]);
    WarehouseProduct::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'qty_on_hand' => 10, 'wac_amount' => 50]);

    $order = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customer->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [['product_id' => $product->id, 'qty_ordered' => 2, 'unit_price_local' => 300]],
    ]);
    $inventory->confirmSaleOrder($order->id);

    return $order->fresh();
}

it('rejects a payment on the invoice of a confirmed-but-unfulfilled sale order', function (): void {
    if (!class_exists('Centrex\\Accounting\\Models\\Account')) {
        $this->markTestSkipped('Accounting package is not available in this test environment.');
    }

    seedAccountsForPaymentGuardTest();
    $order = createConfirmedSaleOrderForPaymentGuardTest();

    app(ErpIntegration::class)->syncSaleOrderDocument($order);
    $invoiceClass = 'Centrex\\Accounting\\Models\\Invoice';
    $invoice = $invoiceClass::findOrFail($order->fresh()->accounting_invoice_id);

    $accounting = app('accounting');
    $accounting->postInvoice($invoice);

    expect(fn () => $accounting->recordInvoicePayment($invoice->fresh(), [
        'date'         => today(),
        'amount'       => 100,
        'method'       => 'cash',
        'account_code' => '1000',
    ]))->toThrow(Centrex\Accounting\Exceptions\InvalidStatusTransitionException::class);
});

it('accepts a payment once the sale order has been fulfilled', function (): void {
    if (!class_exists('Centrex\\Accounting\\Models\\Account')) {
        $this->markTestSkipped('Accounting package is not available in this test environment.');
    }

    seedAccountsForPaymentGuardTest();
    $order = createConfirmedSaleOrderForPaymentGuardTest();
    $inventory = app(Inventory::class);
    $inventory->reserveStock($order->id);
    $inventory->fulfillSaleOrder($order->id);

    // fulfillSaleOrder() already syncs and posts the invoice itself (ErpIntegration::
    // postSaleOrderInvoice() — fulfillment is the revenue-recognition trigger), so it's
    // already posted here; posting it again would throw InvalidStatusTransitionException.
    $invoiceClass = 'Centrex\\Accounting\\Models\\Invoice';
    $invoice = $invoiceClass::findOrFail($order->fresh()->accounting_invoice_id);

    $accounting = app('accounting');
    $payment = $accounting->recordInvoicePayment($invoice->fresh(), [
        'date'         => today(),
        'amount'       => 100,
        'method'       => 'cash',
        'account_code' => '1000',
    ]);

    expect($payment)->not->toBeNull();
});

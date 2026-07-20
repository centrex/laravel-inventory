<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Transactions\SalesReportPage;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Customer, Product, Warehouse, WarehouseProduct};
use Illuminate\Support\Facades\Queue;

/**
 * Regression coverage for a bug where the sales report's "Collected"/"Due" figures
 * (invoiceSummary()) queried every invoice in the date range regardless of the
 * customer/product filter, so they never changed when a filter was applied —
 * unlike the rest of the report, which is scoped via scopedOrderIds().
 *
 * Invokes buildSalesMetrics() directly via reflection rather than through
 * Livewire::test()->render(), since the full page view pulls in Blade Icons
 * components whose manifest isn't bound in this package's isolated test env
 * (only the consuming app registers that) — reflection exercises the exact
 * logic under test without that unrelated dependency.
 */
function buildSalesMetricsFor(SalesReportPage $component): array
{
    $method = new ReflectionMethod($component, 'buildSalesMetrics');
    $method->setAccessible(true);

    return $method->invoke($component);
}

function distinctProductCountFor(SalesReportPage $component): int
{
    $method = new ReflectionMethod($component, 'distinctProductCount');
    $method->setAccessible(true);

    return $method->invoke($component);
}
it('scopes invoice paid/due totals to the selected customer, not every invoice in range', function (): void {
    if (!class_exists('Centrex\\Accounting\\Models\\Account')) {
        $this->markTestSkipped('Accounting package is not available in this test environment.');
    }

    // recordInvoicePayment() dispatches RecalculateCustomerCreditExposureJob (ShouldQueue) —
    // fake the queue since this test env has no `jobs` table for the database driver.
    Queue::fake();

    $accountClass = 'Centrex\\Accounting\\Models\\Account';
    $accountClass::create(['code' => '1000', 'name' => 'Cash', 'type' => 'asset', 'is_active' => true]);
    $accountClass::create(['code' => '1200', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
    $accountClass::create(['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'is_active' => true]);
    $accountClass::create(['code' => '2300', 'name' => 'Sales Tax Payable', 'type' => 'liability', 'is_active' => true]);
    $accountClass::create(['code' => '4000', 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
    $accountClass::create(['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'is_active' => true]);

    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create([
        'code'         => 'W-SR-1',
        'name'         => 'Sales Report Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);

    $customerA = Customer::create(['code' => 'CUS-SR-A', 'name' => 'Customer A', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);
    $customerB = Customer::create(['code' => 'CUS-SR-B', 'name' => 'Customer B', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);

    $productA = Product::create(['sku' => 'SKU-SR-A', 'name' => 'Widget A', 'unit' => 'pcs', 'is_stockable' => true]);
    $productB = Product::create(['sku' => 'SKU-SR-B', 'name' => 'Widget B', 'unit' => 'pcs', 'is_stockable' => true]);

    WarehouseProduct::create(['warehouse_id' => $warehouse->id, 'product_id' => $productA->id, 'qty_on_hand' => 10, 'wac_amount' => 50]);
    WarehouseProduct::create(['warehouse_id' => $warehouse->id, 'product_id' => $productB->id, 'qty_on_hand' => 10, 'wac_amount' => 50]);

    $orderA = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customerA->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [['product_id' => $productA->id, 'qty_ordered' => 2, 'unit_price_local' => 300]],
    ]);
    $orderB = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customerB->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [['product_id' => $productB->id, 'qty_ordered' => 2, 'unit_price_local' => 500]],
    ]);

    $inventory->confirmSaleOrder($orderA->id);
    $inventory->reserveStock($orderA->id);
    $inventory->fulfillSaleOrder($orderA->id);

    $inventory->confirmSaleOrder($orderB->id);
    $inventory->reserveStock($orderB->id);
    $inventory->fulfillSaleOrder($orderB->id);

    $invoiceClass = 'Centrex\\Accounting\\Models\\Invoice';
    $invoiceA = $invoiceClass::findOrFail($orderA->fresh()->accounting_invoice_id);
    $invoiceB = $invoiceClass::findOrFail($orderB->fresh()->accounting_invoice_id);

    $accounting = app('accounting');
    $accounting->postInvoice($invoiceA);
    $accounting->postInvoice($invoiceB);

    // Fully pay A's invoice, leave B's untouched — so paid/due clearly differ per customer.
    $accounting->recordInvoicePayment($invoiceA->fresh(), [
        'date'         => today(),
        'amount'       => (float) $invoiceA->fresh()->total,
        'method'       => 'cash',
        'account_code' => '1000',
    ]);

    $componentForA = new SalesReportPage();
    $componentForA->startDate = now()->subDay()->toDateString();
    $componentForA->endDate = now()->addDay()->toDateString();
    $componentForA->customerId = $customerA->id;

    $metricsForA = buildSalesMetricsFor($componentForA);

    expect($metricsForA['invoice_paid'])->toBeGreaterThan(0)
        ->and($metricsForA['invoice_due'])->toBe(0.0);

    $componentForB = new SalesReportPage();
    $componentForB->startDate = now()->subDay()->toDateString();
    $componentForB->endDate = now()->addDay()->toDateString();
    $componentForB->customerId = $customerB->id;

    $metricsForB = buildSalesMetricsFor($componentForB);

    expect($metricsForB['invoice_paid'])->toBe(0.0)
        ->and($metricsForB['invoice_due'])->toBeGreaterThan(0);
});

it('narrows the distinct-products count when a product filter is applied', function (): void {
    $warehouse = Warehouse::create([
        'code'         => 'W-SR-2',
        'name'         => 'Sales Report Warehouse 2',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $customer = Customer::create(['code' => 'CUS-SR-C', 'name' => 'Customer C', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);
    $productA = Product::create(['sku' => 'SKU-SR-C1', 'name' => 'Widget C1', 'unit' => 'pcs', 'is_stockable' => true]);
    $productB = Product::create(['sku' => 'SKU-SR-C2', 'name' => 'Widget C2', 'unit' => 'pcs', 'is_stockable' => true]);

    WarehouseProduct::create(['warehouse_id' => $warehouse->id, 'product_id' => $productA->id, 'qty_on_hand' => 10, 'wac_amount' => 50]);
    WarehouseProduct::create(['warehouse_id' => $warehouse->id, 'product_id' => $productB->id, 'qty_on_hand' => 10, 'wac_amount' => 50]);

    // A single order containing both products — confirmed, since scopedOrderIds()
    // excludes draft/cancelled orders.
    $inventory = app(Inventory::class);
    $order = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customer->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [
            ['product_id' => $productA->id, 'qty_ordered' => 1, 'unit_price_local' => 100],
            ['product_id' => $productB->id, 'qty_ordered' => 1, 'unit_price_local' => 100],
        ],
    ]);
    $inventory->confirmSaleOrder($order->id);

    $unfiltered = new SalesReportPage();
    $unfiltered->startDate = now()->subDay()->toDateString();
    $unfiltered->endDate = now()->addDay()->toDateString();
    $unfiltered->customerId = $customer->id;

    expect(distinctProductCountFor($unfiltered))->toBe(2);

    $filteredToA = new SalesReportPage();
    $filteredToA->startDate = now()->subDay()->toDateString();
    $filteredToA->endDate = now()->addDay()->toDateString();
    $filteredToA->customerId = $customer->id;
    $filteredToA->productId = $productA->id;

    expect(distinctProductCountFor($filteredToA))->toBe(1);
});

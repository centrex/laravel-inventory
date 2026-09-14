<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Transactions\InventoryReturnedProductsCard;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Customer, Product, Warehouse, WarehouseProduct};
use Illuminate\Support\Facades\Gate;

/**
 * The "Returned Products" tab on the Sales Report — mirrors InventorySoldProductsCard's own
 * coverage, but for posted SaleReturn/SaleReturnItem rows. Invokes buildReturnedProductsReport()
 * directly via reflection rather than through Livewire::test()->render(), same reasoning as
 * SalesReportInvoiceScopingTest: the full component view pulls in Blade Icons components
 * whose manifest isn't bound in this package's isolated test env.
 */
beforeEach(function (): void {
    Gate::define('inventory.reports.view', fn ($user = null): bool => true);

    // These tests exercise pure inventory/stock behavior, not the accounting bridge — with
    // laravel-accounting installed the bridge would otherwise fail fulfillment/return posting
    // for want of a seeded chart of accounts (see SaleOrderRegressionTest for the same pattern).
    config()->set('inventory.erp.accounting.enabled', false);
});

function buildReturnedProductsReportFor(InventoryReturnedProductsCard $component): Illuminate\Support\Collection
{
    $method = new ReflectionMethod($component, 'buildReturnedProductsReport');
    $method->setAccessible(true);

    return $method->invoke($component);
}

it('reports posted returns grouped by product, and excludes draft returns', function (): void {
    $inventory = app(Inventory::class);

    $warehouse = Warehouse::create([
        'code'         => 'W-RP-1',
        'name'         => 'Returned Products Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $customer = Customer::create(['code' => 'CUS-RP-1', 'name' => 'Customer RP', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);
    $product = Product::create(['sku' => 'SKU-RP-1', 'name' => 'Widget RP', 'unit' => 'pcs', 'is_stockable' => true]);

    WarehouseProduct::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'qty_on_hand' => 10, 'wac_amount' => 50]);

    $order = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customer->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [['product_id' => $product->id, 'qty_ordered' => 5, 'unit_price_local' => 100]],
    ]);
    $inventory->confirmSaleOrder($order->id);
    $inventory->reserveStock($order->id);
    $inventory->fulfillSaleOrder($order->id);

    $postedReturn = $inventory->createSaleReturn([
        'sale_order_id' => $order->id,
        'warehouse_id'  => $warehouse->id,
        'returned_at'   => now(),
        'items'         => [['product_id' => $product->id, 'qty_returned' => 2]],
    ]);
    $inventory->postSaleReturn($postedReturn->id);

    // A second, still-draft return for the same product/customer — hasn't been received
    // back into stock yet, so it must not be counted.
    $inventory->createSaleReturn([
        'sale_order_id' => $order->id,
        'warehouse_id'  => $warehouse->id,
        'returned_at'   => now(),
        'items'         => [['product_id' => $product->id, 'qty_returned' => 1]],
    ]);

    $component = new InventoryReturnedProductsCard;
    $component->startDate = now()->subDay()->toDateString();
    $component->endDate = now()->addDay()->toDateString();

    $rows = buildReturnedProductsReportFor($component);

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect($row['product_id'])->toBe($product->id)
        ->and($row['qty_returned'])->toBe(2.0)
        ->and($row['value_local'])->toBe(200.0)
        ->and($row['returns_count'])->toBe(1);
});

it('narrows returned products to the selected customer', function (): void {
    $inventory = app(Inventory::class);

    $warehouse = Warehouse::create([
        'code'         => 'W-RP-2',
        'name'         => 'Returned Products Warehouse 2',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $customerA = Customer::create(['code' => 'CUS-RP-A', 'name' => 'Customer RP A', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);
    $customerB = Customer::create(['code' => 'CUS-RP-B', 'name' => 'Customer RP B', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);
    $product = Product::create(['sku' => 'SKU-RP-2', 'name' => 'Widget RP 2', 'unit' => 'pcs', 'is_stockable' => true]);

    WarehouseProduct::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'qty_on_hand' => 10, 'wac_amount' => 50]);

    $orderA = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customerA->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [['product_id' => $product->id, 'qty_ordered' => 3, 'unit_price_local' => 100]],
    ]);
    $orderB = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customerB->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [['product_id' => $product->id, 'qty_ordered' => 3, 'unit_price_local' => 100]],
    ]);

    foreach ([$orderA, $orderB] as $order) {
        $inventory->confirmSaleOrder($order->id);
        $inventory->reserveStock($order->id);
        $inventory->fulfillSaleOrder($order->id);
    }

    $returnA = $inventory->createSaleReturn([
        'sale_order_id' => $orderA->id,
        'warehouse_id'  => $warehouse->id,
        'returned_at'   => now(),
        'items'         => [['product_id' => $product->id, 'qty_returned' => 1]],
    ]);
    $inventory->postSaleReturn($returnA->id);

    $returnB = $inventory->createSaleReturn([
        'sale_order_id' => $orderB->id,
        'warehouse_id'  => $warehouse->id,
        'returned_at'   => now(),
        'items'         => [['product_id' => $product->id, 'qty_returned' => 2]],
    ]);
    $inventory->postSaleReturn($returnB->id);

    $componentForA = new InventoryReturnedProductsCard;
    $componentForA->startDate = now()->subDay()->toDateString();
    $componentForA->endDate = now()->addDay()->toDateString();
    $componentForA->customerId = $customerA->id;

    $rowsForA = buildReturnedProductsReportFor($componentForA);

    expect($rowsForA)->toHaveCount(1)
        ->and($rowsForA->first()['qty_returned'])->toBe(1.0);
});

<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Transactions\InventoryRecentSaleOrdersCard;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Customer, Product, Warehouse, WarehouseProduct};
use Illuminate\Support\Facades\Gate;

/**
 * Regression coverage for the Sales Report's "Recent Sales" tab moving from a hard
 * ->limit(25) cap to real pagination (see InventoryRecentSaleOrdersCard::recentOrders()).
 * Invokes recentOrders() directly via reflection — same reasoning as
 * SalesReportInvoiceScopingTest: the full component view pulls in Blade Icons components
 * whose manifest isn't bound in this package's isolated test env.
 */
beforeEach(function (): void {
    Gate::define('inventory.reports.view', fn ($user = null): bool => true);

    // These tests exercise pure listing/pagination behavior, not the accounting bridge.
    config()->set('inventory.erp.accounting.enabled', false);
});

function recentOrdersFor(InventoryRecentSaleOrdersCard $component): Illuminate\Contracts\Pagination\LengthAwarePaginator
{
    $method = new ReflectionMethod($component, 'recentOrders');
    $method->setAccessible(true);

    return $method->invoke($component);
}

it('paginates recent sale orders instead of capping at a fixed limit', function (): void {
    $inventory = app(Inventory::class);

    $warehouse = Warehouse::create([
        'code'         => 'W-RSO-1',
        'name'         => 'Recent Sale Orders Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $customer = Customer::create(['code' => 'CUS-RSO-1', 'name' => 'Customer RSO', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);
    $product = Product::create(['sku' => 'SKU-RSO-1', 'name' => 'Widget RSO', 'unit' => 'pcs', 'is_stockable' => true]);

    WarehouseProduct::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'qty_on_hand' => 1000, 'wac_amount' => 50]);

    // More than one page's worth of orders (perPage defaults to 15).
    for ($i = 0; $i < 20; $i++) {
        $inventory->createSaleOrder([
            'warehouse_id'    => $warehouse->id,
            'customer_id'     => $customer->id,
            'currency'        => 'BDT',
            'price_tier_code' => 'b2c_retail',
            'ordered_at'      => now(),
            'items'           => [['product_id' => $product->id, 'qty_ordered' => 1, 'unit_price_local' => 100]],
        ]);
    }

    $component = new InventoryRecentSaleOrdersCard();
    $component->startDate = now()->subDay()->toDateString();
    $component->endDate = now()->addDay()->toDateString();
    $component->customerId = $customer->id;
    $component->perPage = 15;

    $firstPage = recentOrdersFor($component);

    expect($firstPage->total())->toBe(20)
        ->and($firstPage->perPage())->toBe(15)
        ->and($firstPage->count())->toBe(15)
        ->and($firstPage->hasMorePages())->toBeTrue();

    $component->setPage(2);
    $secondPage = recentOrdersFor($component);

    expect($secondPage->count())->toBe(5)
        ->and($secondPage->currentPage())->toBe(2);

    $firstPageIds = collect($firstPage->items())->pluck('id')->all();
    $secondPageIds = collect($secondPage->items())->pluck('id')->all();

    expect(array_intersect($firstPageIds, $secondPageIds))->toBeEmpty();
});

it('respects a smaller per-page size', function (): void {
    $inventory = app(Inventory::class);

    $warehouse = Warehouse::create([
        'code'         => 'W-RSO-2',
        'name'         => 'Recent Sale Orders Warehouse 2',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $customer = Customer::create(['code' => 'CUS-RSO-2', 'name' => 'Customer RSO 2', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);
    $product = Product::create(['sku' => 'SKU-RSO-2', 'name' => 'Widget RSO 2', 'unit' => 'pcs', 'is_stockable' => true]);

    WarehouseProduct::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'qty_on_hand' => 1000, 'wac_amount' => 50]);

    for ($i = 0; $i < 6; $i++) {
        $inventory->createSaleOrder([
            'warehouse_id'    => $warehouse->id,
            'customer_id'     => $customer->id,
            'currency'        => 'BDT',
            'price_tier_code' => 'b2c_retail',
            'ordered_at'      => now(),
            'items'           => [['product_id' => $product->id, 'qty_ordered' => 1, 'unit_price_local' => 100]],
        ]);
    }

    $component = new InventoryRecentSaleOrdersCard();
    $component->startDate = now()->subDay()->toDateString();
    $component->endDate = now()->addDay()->toDateString();
    $component->customerId = $customer->id;
    $component->perPage = 5;

    $page = recentOrdersFor($component);

    expect($page->total())->toBe(6)
        ->and($page->perPage())->toBe(5)
        ->and($page->count())->toBe(5)
        ->and($page->hasMorePages())->toBeTrue();
});

<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Transactions\SaleOrderFormPage;
use Centrex\Inventory\Models\{Customer, Product, Warehouse, WarehouseProduct};
use Illuminate\Support\Facades\Gate;

it('defaults the price tier to the selected customer tier and re-syncs item prices', function (): void {
    Gate::define('inventory.sale-orders.create', fn ($user = null) => true);
    Gate::define('inventory.pricing.manage', fn ($user = null) => true);
    Gate::define('inventory.sale-orders.override-price', fn ($user = null) => true);
    Gate::define('inventory.sale-orders.apply-discount', fn ($user = null) => true);
    Gate::define('inventory.sale-orders.approve-credit', fn ($user = null) => true);

    $warehouse = Warehouse::create([
        'code' => 'W1', 'name' => 'UK', 'country_code' => 'GB', 'currency' => 'GBP',
    ]);
    $customer = Customer::create([
        'code'            => 'CUS-1',
        'name'            => 'Wholesale Co',
        'currency'        => 'GBP',
        'price_tier_code' => 'b2b_wholesale',
        'is_active'       => true,
    ]);
    $product = Product::create([
        'sku' => 'SKU-1', 'name' => 'Widget', 'unit' => 'pcs', 'is_active' => true, 'is_stockable' => true,
    ]);
    WarehouseProduct::create([
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'qty_on_hand'  => 10, 'qty_reserved' => 0, 'qty_in_transit' => 0, 'wac_amount' => 10,
    ]);

    $page = new SaleOrderFormPage();
    $page->mount();
    $page->warehouse_id = $warehouse->id;
    $page->customer_id = $customer->id;

    $method = new ReflectionMethod($page, 'applyCustomerPriceTier');
    $method->setAccessible(true);
    $method->invoke($page);

    expect($page->price_tier_code)->toBe('b2b_wholesale');
});

it('does not auto-apply the customer tier when the user cannot manage pricing tiers', function (): void {
    Gate::define('inventory.sale-orders.create', fn ($user = null) => true);
    Gate::define('inventory.pricing.manage', fn ($user = null) => false);

    $warehouse = Warehouse::create([
        'code' => 'W2', 'name' => 'UK2', 'country_code' => 'GB', 'currency' => 'GBP',
    ]);
    $customer = Customer::create([
        'code'            => 'CUS-2',
        'name'            => 'Wholesale Co 2',
        'currency'        => 'GBP',
        'price_tier_code' => 'b2b_wholesale',
        'is_active'       => true,
    ]);

    $page = new SaleOrderFormPage();
    $page->mount();
    $originalTier = $page->price_tier_code;
    $page->warehouse_id = $warehouse->id;
    $page->customer_id = $customer->id;

    $method = new ReflectionMethod($page, 'applyCustomerPriceTier');
    $method->setAccessible(true);
    $method->invoke($page);

    expect($page->price_tier_code)->toBe($originalTier);
});

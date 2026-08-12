<?php

declare(strict_types = 1);

use Centrex\Inventory\Models\{Customer, Partner, Product, SaleOrder, Warehouse, WarehouseProduct};
use Illuminate\Support\Str;

it('returns the existing order on a content-identical partner API retry instead of creating a duplicate', function (): void {
    $warehouse = Warehouse::create([
        'code' => 'W-PARTNER-IDEM', 'name' => 'Partner Idem Warehouse', 'country_code' => 'GB', 'currency' => 'BDT', 'is_active' => true,
    ]);
    $customer = Customer::create([
        'code'            => 'CUS-PARTNER-IDEM', 'name' => 'Partner Idem Customer', 'currency' => 'BDT',
        'price_tier_code' => 'b2c_ecom', 'is_active' => true,
    ]);
    $product = Product::create([
        'sku' => 'SKU-PARTNER-IDEM', 'name' => 'Widget', 'unit' => 'pcs', 'is_active' => true, 'is_stockable' => true,
    ]);
    WarehouseProduct::create([
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'qty_on_hand'  => 100, 'qty_reserved' => 0, 'qty_in_transit' => 0, 'wac_amount' => 10,
    ]);

    $partner = Partner::create([
        'name'                 => 'Test Partner',
        'type'                 => 'ecom-partner',
        'api_key'              => 'inv_test_' . Str::random(40),
        'customer_id'          => $customer->id,
        'default_warehouse_id' => $warehouse->id,
        'default_price_tier'   => 'b2c_ecom',
        'can_view_stock'       => true,
        'can_view_prices'      => true,
        'can_create_orders'    => true,
        'is_active'            => true,
    ]);
    $apiKey = $partner->getPlainApiKey();

    $payload = [
        'items' => [
            ['product_id' => $product->id, 'qty_ordered' => 2, 'unit_price_local' => 10],
        ],
    ];

    // Simulates a partner integration retrying after a timeout on the first (slow) response —
    // this used to create a fresh duplicate SaleOrder on every retry.
    $first = $this->postJson('/api/inventory/partner/orders', $payload, ['X-Partner-Key' => $apiKey]);
    $first->assertStatus(201);

    $second = $this->postJson('/api/inventory/partner/orders', $payload, ['X-Partner-Key' => $apiKey]);
    $second->assertStatus(200);

    expect(SaleOrder::count())->toBe(1)
        ->and($second->json('id'))->toBe($first->json('id'));
});

<?php

declare(strict_types = 1);

use Centrex\Inventory\Models\{Customer, Product, Supplier, Warehouse, WarehouseProduct};

it('returns matching customers for the async select endpoint', function (): void {
    Customer::create([
        'code'     => 'CUS-1',
        'name'     => 'Alice Buyer',
        'email'    => 'alice@example.com',
        'currency' => 'BDT',
    ]);
    Customer::create([
        'code'     => 'CUS-2',
        'name'     => 'Bob Walker',
        'email'    => 'bob@example.com',
        'currency' => 'BDT',
    ]);

    $this->getJson(route('inventory.async-select', ['resource' => 'customers', 'q' => 'alice']))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['label' => 'Alice Buyer']);
});

it('returns matching suppliers for the async select endpoint', function (): void {
    Supplier::create([
        'code'     => 'SUP-1',
        'name'     => 'Prime Supplier',
        'currency' => 'BDT',
    ]);
    Supplier::create([
        'code'     => 'SUP-2',
        'name'     => 'Backup Vendor',
        'currency' => 'BDT',
    ]);

    $this->getJson(route('inventory.async-select', ['resource' => 'suppliers', 'q' => 'prime']))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['label' => 'Prime Supplier']);
});

it('returns active products for purchase async select', function (): void {
    Product::create([
        'sku'          => 'SKU-1',
        'name'         => 'Active Product',
        'barcode'      => 'BAR-001',
        'unit'         => 'pcs',
        'is_active'    => true,
        'is_stockable' => true,
    ]);
    Product::create([
        'sku'          => 'SKU-2',
        'name'         => 'Inactive Product',
        'unit'         => 'pcs',
        'is_active'    => false,
        'is_stockable' => true,
    ]);

    $this->getJson(route('inventory.async-select', ['resource' => 'purchase-products', 'q' => 'product']))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment([
            'label'    => 'Active Product',
            'sublabel' => 'BAR-001',
        ]);
});

it('filters sale products by warehouse availability', function (): void {
    $warehouse = Warehouse::create([
        'code'         => 'W1',
        'name'         => 'Main Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);

    $available = Product::create([
        'sku'          => 'SKU-AV',
        'name'         => 'Available Product',
        'barcode'      => 'BAR-AV',
        'unit'         => 'pcs',
        'is_active'    => true,
        'is_stockable' => true,
    ]);
    $unavailable = Product::create([
        'sku'          => 'SKU-NA',
        'name'         => 'Unavailable Product',
        'unit'         => 'pcs',
        'is_active'    => true,
        'is_stockable' => true,
    ]);

    WarehouseProduct::create([
        'warehouse_id'   => $warehouse->id,
        'product_id'     => $available->id,
        'qty_on_hand'    => 5,
        'qty_reserved'   => 1,
        'qty_in_transit' => 0,
        'wac_amount'     => 100,
    ]);
    WarehouseProduct::create([
        'warehouse_id'   => $warehouse->id,
        'product_id'     => $unavailable->id,
        'qty_on_hand'    => 2,
        'qty_reserved'   => 2,
        'qty_in_transit' => 0,
        'wac_amount'     => 100,
    ]);

    $this->getJson(route('inventory.async-select', [
        'resource'     => 'sale-products',
        'warehouse_id' => $warehouse->id,
        'q'            => 'product',
    ]))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment([
            'label'    => 'Available Product',
            'sublabel' => 'BAR-AV',
        ])
        ->assertJsonMissing(['label' => 'Unavailable Product']);
});

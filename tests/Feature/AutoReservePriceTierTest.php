<?php

declare(strict_types = 1);

use Centrex\Inventory\Enums\SaleOrderStatus;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Customer, Product, Warehouse, WarehouseProduct};

function makeSaleOrderFixtures(string $suffix): array
{
    $warehouse = Warehouse::create([
        'code'         => "W-ART-{$suffix}",
        'name'         => "Auto Reserve Tier Warehouse {$suffix}",
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $customer = Customer::create([
        'code'      => "CUS-ART-{$suffix}",
        'name'      => "Auto Reserve Tier Customer {$suffix}",
        'currency'  => 'BDT',
        'is_active' => true,
    ]);
    $product = Product::create([
        'sku'          => "SKU-ART-{$suffix}",
        'name'         => "Auto Reserve Tier Widget {$suffix}",
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);
    WarehouseProduct::create([
        'warehouse_id' => $warehouse->id,
        'product_id'   => $product->id,
        'qty_on_hand'  => 10,
        'wac_amount'   => 50,
    ]);

    return [$warehouse, $customer, $product];
}

it('auto-reserves stock on confirm for the b2c_ecom price tier by default', function (): void {
    [$warehouse, $customer, $product] = makeSaleOrderFixtures('ECOM');

    $inventory = app(Inventory::class);
    $saleOrder = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customer->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_ecom',
        'items'           => [[
            'product_id'       => $product->id,
            'qty_ordered'      => 2,
            'unit_price_local' => 100,
        ]],
    ]);

    $confirmed = $inventory->confirmSaleOrder($saleOrder->id);

    expect($confirmed->status)->toBe(SaleOrderStatus::PROCESSING)
        ->and(WarehouseProduct::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first()->qty_reserved)
        ->toEqual(2.0);
});

it('does not auto-reserve stock on confirm for other price tiers by default', function (): void {
    [$warehouse, $customer, $product] = makeSaleOrderFixtures('RETAIL');

    $inventory = app(Inventory::class);
    $saleOrder = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customer->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [[
            'product_id'       => $product->id,
            'qty_ordered'      => 2,
            'unit_price_local' => 100,
        ]],
    ]);

    $confirmed = $inventory->confirmSaleOrder($saleOrder->id);

    expect($confirmed->status)->toBe(SaleOrderStatus::CONFIRMED)
        ->and(WarehouseProduct::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first()->qty_reserved)
        ->toEqual(0.0);

    // Manual reserve step still works for non-auto-reserving tiers.
    $reserved = $inventory->reserveStock($saleOrder->id);
    expect($reserved->status)->toBe(SaleOrderStatus::PROCESSING);
});

it('auto-reserves every tier when inventory.auto_reserve_on_confirm is forced true', function (): void {
    config(['inventory.auto_reserve_on_confirm' => true]);

    [$warehouse, $customer, $product] = makeSaleOrderFixtures('FORCED');

    $inventory = app(Inventory::class);
    $saleOrder = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customer->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2b_wholesale',
        'items'           => [[
            'product_id'       => $product->id,
            'qty_ordered'      => 2,
            'unit_price_local' => 100,
        ]],
    ]);

    $confirmed = $inventory->confirmSaleOrder($saleOrder->id);

    expect($confirmed->status)->toBe(SaleOrderStatus::PROCESSING);
});

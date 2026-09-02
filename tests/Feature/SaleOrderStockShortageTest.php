<?php

declare(strict_types = 1);

use Centrex\Inventory\Enums\SaleOrderStatus;
use Centrex\Inventory\Exceptions\InsufficientStockException;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Customer, Product, Warehouse, WarehouseProduct};

function makeShortageFixtures(string $suffix, float $qtyOnHand = 1): array
{
    $warehouse = Warehouse::create([
        'code'         => "W-SHORT-{$suffix}",
        'name'         => "Stock Shortage Warehouse {$suffix}",
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $customer = Customer::create([
        'code'      => "CUS-SHORT-{$suffix}",
        'name'      => "Stock Shortage Customer {$suffix}",
        'currency'  => 'BDT',
        'is_active' => true,
    ]);
    $product = Product::create([
        'sku'          => "SKU-SHORT-{$suffix}",
        'name'         => "Stock Shortage Widget {$suffix}",
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);
    WarehouseProduct::create([
        'warehouse_id' => $warehouse->id,
        'product_id'   => $product->id,
        'qty_on_hand'  => $qtyOnHand,
        'wac_amount'   => 50,
    ]);

    return [$warehouse, $customer, $product];
}

it('refuses to reserve stock for a sale order that exceeds available quantity', function (): void {
    [$warehouse, $customer, $product] = makeShortageFixtures('RESERVE', qtyOnHand: 1);

    $inventory = app(Inventory::class);
    $saleOrder = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customer->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [[
            'product_id'       => $product->id,
            'qty_ordered'      => 5,
            'unit_price_local' => 100,
        ]],
    ]);

    $inventory->confirmSaleOrder($saleOrder->id);

    expect(fn () => $inventory->reserveStock($saleOrder->id))
        ->toThrow(InsufficientStockException::class);

    $saleOrder->refresh();
    expect($saleOrder->status)->toBe(SaleOrderStatus::CONFIRMED);

    $wp = WarehouseProduct::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
    expect($wp->qty_reserved)->toEqual(0.0);
});

it('rolls back confirmation entirely when an auto-reserving tier is short on stock', function (): void {
    [$warehouse, $customer, $product] = makeShortageFixtures('CONFIRM', qtyOnHand: 1);

    $inventory = app(Inventory::class);
    $saleOrder = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customer->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_ecom',
        'items'           => [[
            'product_id'       => $product->id,
            'qty_ordered'      => 5,
            'unit_price_local' => 100,
        ]],
    ]);

    expect(fn () => $inventory->confirmSaleOrder($saleOrder->id))
        ->toThrow(InsufficientStockException::class);

    $saleOrder->refresh();
    expect($saleOrder->status)->toBe(SaleOrderStatus::DRAFT);

    $wp = WarehouseProduct::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
    expect($wp->qty_reserved)->toEqual(0.0);
});

it('tallies multiple lines against the same product before checking availability', function (): void {
    [$warehouse, $customer, $product] = makeShortageFixtures('TALLY', qtyOnHand: 5);

    $inventory = app(Inventory::class);
    $saleOrder = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customer->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [
            ['product_id' => $product->id, 'qty_ordered' => 3, 'unit_price_local' => 100],
            ['product_id' => $product->id, 'qty_ordered' => 3, 'unit_price_local' => 100],
        ],
    ]);

    $inventory->confirmSaleOrder($saleOrder->id);

    // Each line alone (3) fits within qty_on_hand (5), but the two lines together (6) don't.
    expect(fn () => $inventory->reserveStock($saleOrder->id))
        ->toThrow(InsufficientStockException::class);

    $wp = WarehouseProduct::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
    expect($wp->qty_reserved)->toEqual(0.0);
});

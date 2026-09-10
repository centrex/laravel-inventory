<?php

declare(strict_types = 1);

use Centrex\Inventory\Enums\SaleOrderStatus;
use Centrex\Inventory\Exceptions\{InsufficientStockException, InvalidTransitionException};
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Lot, Product, SerialNumber, Warehouse, WarehouseProduct};

/**
 * Regression coverage for the sale side of the inventory lifecycle: sale order state
 * transitions, reservation/cancellation stock accounting, fulfillment (damaged bin, FIFO/LIFO
 * lot selection, serial numbers, WAC-based COGS), and sale returns. See
 * SaleOrderStockShortageTest for reserve/confirm shortage handling and multi-line pooling, and
 * InventoryWorkflowTest for credit limits, coupons, and quotation/requisition conversion — this
 * file targets the gaps around damaged-bin fulfillment, lot costing, serial tracking, partial
 * fulfillment, and releasing a genuinely-reserved (not just confirmed) order, which had no
 * dedicated coverage before.
 */
function soFixtures(string $suffix, float $qtyOnHand = 10, array $productAttrs = []): array
{
    // These tests exercise pure inventory/stock behavior; accounting sync is covered
    // separately in InventoryWorkflowTest's dedicated ERP-bridge tests.
    config()->set('inventory.erp.accounting.enabled', false);

    $warehouse = Warehouse::create([
        'code' => "W-SO-{$suffix}", 'name' => "SO Warehouse {$suffix}", 'country_code' => 'BD', 'currency' => 'BDT',
    ]);
    $product = Product::create(array_merge([
        'sku' => "SKU-SO-{$suffix}", 'name' => "SO Product {$suffix}", 'unit' => 'pcs', 'is_stockable' => true,
    ], $productAttrs));

    $wp = WarehouseProduct::create([
        'warehouse_id' => $warehouse->id,
        'product_id'   => $product->id,
        'qty_on_hand'  => $qtyOnHand,
        'wac_amount'   => 100,
    ]);

    return [$warehouse, $product, $wp];
}

it('rejects sale order transitions attempted out of order', function (): void {
    [$warehouse, $product] = soFixtures('ORDER');
    $inventory = app(Inventory::class);

    $so = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [['product_id' => $product->id, 'qty_ordered' => 2, 'unit_price_local' => 150]],
    ]);

    expect(fn () => $inventory->reserveStock($so->id))->toThrow(InvalidTransitionException::class);
    expect(fn () => $inventory->fulfillSaleOrder($so->id))->toThrow(InvalidTransitionException::class);

    $inventory->confirmSaleOrder($so->id);

    expect(fn () => $inventory->fulfillSaleOrder($so->id))->toThrow(InvalidTransitionException::class);
});

it('rejects reserving stock twice for the same sale order', function (): void {
    [$warehouse, $product] = soFixtures('DBLRESERVE');
    $inventory = app(Inventory::class);

    $so = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [['product_id' => $product->id, 'qty_ordered' => 2, 'unit_price_local' => 150]],
    ]);
    $inventory->confirmSaleOrder($so->id);
    $inventory->reserveStock($so->id);

    expect(fn () => $inventory->reserveStock($so->id))->toThrow(InvalidTransitionException::class);
});

it('releases reserved stock when cancelling an order that was reserved but never fulfilled', function (): void {
    [$warehouse, $product, $wp] = soFixtures('CANCELRESERVED');
    $inventory = app(Inventory::class);

    $so = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [['product_id' => $product->id, 'qty_ordered' => 4, 'unit_price_local' => 150]],
    ]);
    $inventory->confirmSaleOrder($so->id);
    $inventory->reserveStock($so->id);

    expect((float) $wp->fresh()->qty_reserved)->toBe(4.0);

    $inventory->cancelSaleOrder($so->id);

    expect((float) $wp->fresh()->qty_reserved)->toBe(0.0);
    expect((float) $wp->fresh()->qty_on_hand)->toBe(10.0);
});

it('releases only the still-open quantity when cancelling a partially fulfilled sale order', function (): void {
    [$warehouse, $product, $wp] = soFixtures('CANCELPARTIAL');
    $inventory = app(Inventory::class);

    $so = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [['product_id' => $product->id, 'qty_ordered' => 6, 'unit_price_local' => 150]],
    ]);
    $inventory->confirmSaleOrder($so->id);
    $inventory->reserveStock($so->id);

    $itemId = $so->fresh('items')->items->first()->id;
    $inventory->fulfillSaleOrder($so->id, [$itemId => 2]);

    expect($so->fresh()->status)->toBe(SaleOrderStatus::PARTIAL);
    expect((float) $wp->fresh()->qty_reserved)->toBe(4.0);
    expect((float) $wp->fresh()->qty_on_hand)->toBe(8.0);

    $inventory->cancelSaleOrder($so->id);

    // Only the 4 still-open units are released; the 2 already fulfilled stay sold.
    expect((float) $wp->fresh()->qty_reserved)->toBe(0.0);
    expect((float) $wp->fresh()->qty_on_hand)->toBe(8.0);
});

it('supports partial fulfillment across multiple calls before marking the order fulfilled', function (): void {
    [$warehouse, $product, $wp] = soFixtures('PARTIALFULFILL');
    $inventory = app(Inventory::class);

    $so = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [['product_id' => $product->id, 'qty_ordered' => 6, 'unit_price_local' => 150]],
    ]);
    $inventory->confirmSaleOrder($so->id);
    $inventory->reserveStock($so->id);
    $itemId = $so->fresh('items')->items->first()->id;

    $so = $inventory->fulfillSaleOrder($so->id, [$itemId => 2]);
    expect($so->status)->toBe(SaleOrderStatus::PARTIAL);

    $so = $inventory->fulfillSaleOrder($so->id, [$itemId => 4]);
    // With no linked accounting invoice (ERP disabled here), full fulfillment zeroes
    // due_amount immediately, which auto-promotes FULFILLED straight to COMPLETED.
    expect($so->status)->toBe(SaleOrderStatus::COMPLETED);
    expect((float) $wp->fresh()->qty_on_hand)->toBe(4.0);
    expect((float) $wp->fresh()->qty_reserved)->toBe(0.0);
});

it('fulfills from the damaged bin without touching on-hand or reserved quantities', function (): void {
    [$warehouse, $product, $wp] = soFixtures('DAMAGEDFULFILL');
    $wp->update(['qty_damaged' => 5]);
    $inventory = app(Inventory::class);

    $so = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        // from_damaged lives on the item row itself — fulfillSaleOrder() only falls back to the
        // per-call override when this column is null, and it defaults to false, not null.
        'items' => [['product_id' => $product->id, 'qty_ordered' => 2, 'unit_price_local' => 150, 'from_damaged' => true]],
    ]);
    $inventory->confirmSaleOrder($so->id);
    $inventory->reserveStock($so->id);
    $itemId = $so->fresh('items')->items->first()->id;

    $inventory->fulfillSaleOrder($so->id, [$itemId => ['qty' => 2, 'from_damaged' => true]]);

    $wp->refresh();
    expect((float) $wp->qty_damaged)->toBe(3.0);
    // Untouched by the damaged-bin path — still whatever reserveStock() set them to.
    expect((float) $wp->qty_on_hand)->toBe(10.0);
    expect((float) $wp->qty_reserved)->toBe(2.0);
});

it('rejects fulfilling more from the damaged bin than is available', function (): void {
    [$warehouse, $product, $wp] = soFixtures('DAMAGEDSHORT');
    $wp->update(['qty_damaged' => 1]);
    $inventory = app(Inventory::class);

    $so = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [['product_id' => $product->id, 'qty_ordered' => 2, 'unit_price_local' => 150, 'from_damaged' => true]],
    ]);
    $inventory->confirmSaleOrder($so->id);
    $inventory->reserveStock($so->id);
    $itemId = $so->fresh('items')->items->first()->id;

    expect(fn () => $inventory->fulfillSaleOrder($so->id, [$itemId => ['qty' => 2, 'from_damaged' => true]]))
        ->toThrow(InsufficientStockException::class);
});

it('auto-selects the oldest lot under FIFO costing when no lot is specified', function (): void {
    [$warehouse, $product, $wp] = soFixtures('FIFO', qtyOnHand: 10, productAttrs: ['costing_method' => 'fifo']);
    $inventory = app(Inventory::class);

    $lotOld = Lot::create([
        'product_id'  => $product->id, 'warehouse_id' => $warehouse->id, 'lot_number' => 'LOT-OLD',
        'qty_initial' => 5, 'qty_on_hand' => 5, 'unit_cost_amount' => 100,
    ]);
    $lotOld->forceFill(['created_at' => now()->subDays(2)])->save();

    $lotNew = Lot::create([
        'product_id'  => $product->id, 'warehouse_id' => $warehouse->id, 'lot_number' => 'LOT-NEW',
        'qty_initial' => 5, 'qty_on_hand' => 5, 'unit_cost_amount' => 200,
    ]);

    $so = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [['product_id' => $product->id, 'qty_ordered' => 3, 'unit_price_local' => 150]],
    ]);
    $inventory->confirmSaleOrder($so->id);
    $inventory->reserveStock($so->id);
    $inventory->fulfillSaleOrder($so->id);

    $item = $so->fresh('items')->items->first();
    expect($item->lot_id)->toBe($lotOld->id);
    expect((float) $item->unit_cost_amount)->toBe(100.0);
    expect((float) $lotOld->fresh()->qty_on_hand)->toBe(2.0);
    expect((float) $lotNew->fresh()->qty_on_hand)->toBe(5.0);
});

it('auto-selects the newest lot under LIFO costing when no lot is specified', function (): void {
    [$warehouse, $product, $wp] = soFixtures('LIFO', qtyOnHand: 10, productAttrs: ['costing_method' => 'lifo']);
    $inventory = app(Inventory::class);

    $lotOld = Lot::create([
        'product_id'  => $product->id, 'warehouse_id' => $warehouse->id, 'lot_number' => 'LOT-OLD',
        'qty_initial' => 5, 'qty_on_hand' => 5, 'unit_cost_amount' => 100,
    ]);
    $lotOld->forceFill(['created_at' => now()->subDays(2)])->save();

    $lotNew = Lot::create([
        'product_id'  => $product->id, 'warehouse_id' => $warehouse->id, 'lot_number' => 'LOT-NEW',
        'qty_initial' => 5, 'qty_on_hand' => 5, 'unit_cost_amount' => 200,
    ]);

    $so = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [['product_id' => $product->id, 'qty_ordered' => 3, 'unit_price_local' => 150]],
    ]);
    $inventory->confirmSaleOrder($so->id);
    $inventory->reserveStock($so->id);
    $inventory->fulfillSaleOrder($so->id);

    $item = $so->fresh('items')->items->first();
    expect($item->lot_id)->toBe($lotNew->id);
    expect((float) $item->unit_cost_amount)->toBe(200.0);
    expect((float) $lotNew->fresh()->qty_on_hand)->toBe(2.0);
    expect((float) $lotOld->fresh()->qty_on_hand)->toBe(5.0);
});

it('marks specified serial numbers as sold on fulfillment', function (): void {
    [$warehouse, $product, $wp] = soFixtures('SERIAL');
    $inventory = app(Inventory::class);

    $serialOne = SerialNumber::create([
        'serial_number' => 'SN-1', 'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
        'status'        => SerialNumber::STATUS_AVAILABLE,
    ]);
    $serialTwo = SerialNumber::create([
        'serial_number' => 'SN-2', 'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
        'status'        => SerialNumber::STATUS_AVAILABLE,
    ]);

    $so = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [['product_id' => $product->id, 'qty_ordered' => 2, 'unit_price_local' => 150]],
    ]);
    $inventory->confirmSaleOrder($so->id);
    $inventory->reserveStock($so->id);
    $itemId = $so->fresh('items')->items->first()->id;

    $inventory->fulfillSaleOrder($so->id, [$itemId => ['qty' => 2, 'serial_ids' => [$serialOne->id, $serialTwo->id]]]);

    expect($serialOne->fresh()->status)->toBe(SerialNumber::STATUS_SOLD);
    expect($serialOne->fresh()->sale_order_item_id)->toBe($itemId);
    expect($serialTwo->fresh()->status)->toBe(SerialNumber::STATUS_SOLD);
});

it('records the current WAC as COGS on each fulfilled line', function (): void {
    [$warehouse, $product, $wp] = soFixtures('COGS');
    $wp->update(['wac_amount' => 175]);
    $inventory = app(Inventory::class);

    $so = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [['product_id' => $product->id, 'qty_ordered' => 3, 'unit_price_local' => 300]],
    ]);
    $inventory->confirmSaleOrder($so->id);
    $inventory->reserveStock($so->id);
    $inventory->fulfillSaleOrder($so->id);

    $item = $so->fresh('items')->items->first();
    expect((float) $item->unit_cost_amount)->toBe(175.0);
});

it('creates and posts a sale return that restores stock and blends WAC', function (): void {
    [$warehouse, $product, $wp] = soFixtures('RETURN');
    $inventory = app(Inventory::class);

    $so = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [['product_id' => $product->id, 'qty_ordered' => 4, 'unit_price_local' => 300]],
    ]);
    $inventory->confirmSaleOrder($so->id);
    $inventory->reserveStock($so->id);
    $inventory->fulfillSaleOrder($so->id);

    expect((float) $wp->fresh()->qty_on_hand)->toBe(6.0);

    $return = $inventory->createSaleReturn([
        'sale_order_id' => $so->id,
        'warehouse_id'  => $warehouse->id,
        'returned_at'   => now(),
        'items'         => [[
            'product_id'        => $product->id,
            'qty_returned'      => 2,
            'unit_price_amount' => 300,
            'unit_cost_amount'  => 150,
        ]],
    ]);
    $return = $inventory->postSaleReturn($return->id);

    expect($return->status)->toBe('posted');

    // (6 * 100 + 2 * 150) / 8 = 112.5
    expect((float) $wp->fresh()->qty_on_hand)->toBe(8.0);
    expect((float) $wp->fresh()->wac_amount)->toBe(112.5);
});

it('rejects posting the same sale return twice', function (): void {
    [$warehouse, $product] = soFixtures('DBLRETURN');
    $inventory = app(Inventory::class);

    $so = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [['product_id' => $product->id, 'qty_ordered' => 4, 'unit_price_local' => 300]],
    ]);
    $inventory->confirmSaleOrder($so->id);
    $inventory->reserveStock($so->id);
    $inventory->fulfillSaleOrder($so->id);

    $return = $inventory->createSaleReturn([
        'sale_order_id' => $so->id,
        'warehouse_id'  => $warehouse->id,
        'returned_at'   => now(),
        'items'         => [[
            'product_id'        => $product->id,
            'qty_returned'      => 1,
            'unit_price_amount' => 300,
            'unit_cost_amount'  => 150,
        ]],
    ]);
    $inventory->postSaleReturn($return->id);

    expect(fn () => $inventory->postSaleReturn($return->id))->toThrow(InvalidTransitionException::class);
});

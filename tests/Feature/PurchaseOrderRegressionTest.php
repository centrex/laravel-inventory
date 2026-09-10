<?php

declare(strict_types = 1);

use Centrex\Inventory\Enums\{PurchaseOrderStatus, StockReceiptStatus};
use Centrex\Inventory\Exceptions\{InsufficientStockException, InvalidTransitionException};
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Lot, Product, Supplier, Warehouse, WarehouseProduct};

/**
 * Regression coverage for the purchase side of the inventory lifecycle: purchase order state
 * transitions, GRN posting/voiding (WAC, damaged/lost splitting, lot tracking), and purchase
 * returns. See InventoryWorkflowTest for the already-covered happy-path draft→received flow,
 * requisition conversion, and cross-PO item guard — this file targets the gaps around WAC math,
 * damaged/lost receiving, lot accumulation, voiding, and purchase returns, which had no
 * dedicated coverage before.
 */
function poFixtures(string $suffix): array
{
    // These tests exercise pure inventory/stock behavior; accounting sync is covered
    // separately in InventoryWorkflowTest's dedicated ERP-bridge tests.
    config()->set('inventory.erp.accounting.enabled', false);

    $warehouse = Warehouse::create([
        'code' => "W-PO-{$suffix}", 'name' => "PO Warehouse {$suffix}", 'country_code' => 'BD', 'currency' => 'BDT',
    ]);
    $supplier = Supplier::create([
        'code' => "SUP-PO-{$suffix}", 'name' => "PO Supplier {$suffix}", 'currency' => 'BDT',
    ]);
    $product = Product::create([
        'sku' => "SKU-PO-{$suffix}", 'name' => "PO Product {$suffix}", 'unit' => 'pcs', 'is_stockable' => true,
    ]);

    return [$warehouse, $supplier, $product];
}

it('rejects purchase order transitions attempted out of order', function (): void {
    [$warehouse, $supplier, $product] = poFixtures('ORDER');
    $inventory = app(Inventory::class);

    $po = $inventory->createPurchaseOrder([
        'warehouse_id' => $warehouse->id,
        'supplier_id'  => $supplier->id,
        'currency'     => 'BDT',
        'items'        => [['product_id' => $product->id, 'qty_ordered' => 5, 'unit_price_local' => 100]],
    ]);

    expect(fn () => $inventory->confirmPurchaseOrder($po->id))->toThrow(InvalidTransitionException::class);
    expect(fn () => $inventory->receivePurchaseOrder($po->id))->toThrow(InvalidTransitionException::class);

    $po = $inventory->submitPurchaseOrder($po->id);

    expect(fn () => $inventory->receivePurchaseOrder($po->id))->toThrow(InvalidTransitionException::class);
});

it('blends WAC using the weighted-average formula when receiving into existing stock', function (): void {
    [$warehouse, $supplier, $product] = poFixtures('WAC');
    $inventory = app(Inventory::class);

    WarehouseProduct::create([
        'warehouse_id' => $warehouse->id,
        'product_id'   => $product->id,
        'qty_on_hand'  => 10,
        'wac_amount'   => 100,
    ]);

    $po = $inventory->createPurchaseOrder([
        'warehouse_id' => $warehouse->id,
        'supplier_id'  => $supplier->id,
        'currency'     => 'BDT',
        'items'        => [['product_id' => $product->id, 'qty_ordered' => 10, 'unit_price_local' => 200]],
    ]);
    $inventory->submitPurchaseOrder($po->id);
    $inventory->confirmPurchaseOrder($po->id);
    $inventory->receivePurchaseOrder($po->id);

    $wp = WarehouseProduct::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();

    // (10 * 100 + 10 * 200) / 20 = 150
    expect((float) $wp->qty_on_hand)->toBe(20.0);
    expect((float) $wp->wac_amount)->toBe(150.0);
});

it('excludes damaged and lost units from qty_on_hand and WAC while tracking the damaged bin separately', function (): void {
    [$warehouse, $supplier, $product] = poFixtures('DAMAGED');
    $inventory = app(Inventory::class);

    $po = $inventory->createPurchaseOrder([
        'warehouse_id' => $warehouse->id,
        'supplier_id'  => $supplier->id,
        'currency'     => 'BDT',
        'items'        => [['product_id' => $product->id, 'qty_ordered' => 10, 'unit_price_local' => 100]],
    ]);
    $poItem = $po->fresh('items')->items->first();

    $grn = $inventory->createStockReceipt($po->id, [[
        'purchase_order_item_id' => $poItem->id,
        'qty_received'           => 10,
        'qty_damaged'            => 3,
        'qty_lost'               => 2,
    ]]);
    $inventory->postStockReceipt($grn->id);

    $wp = WarehouseProduct::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();

    // 10 received - 3 damaged - 2 lost = 5 good units join qty_on_hand/WAC.
    expect((float) $wp->qty_on_hand)->toBe(5.0);
    expect((float) $wp->wac_amount)->toBe(100.0);
    expect((float) $wp->qty_damaged)->toBe(3.0);
});

it('accumulates lot quantity across multiple receipts and reverses it on void', function (): void {
    [$warehouse, $supplier, $product] = poFixtures('LOT');
    $inventory = app(Inventory::class);

    $po = $inventory->createPurchaseOrder([
        'warehouse_id' => $warehouse->id,
        'supplier_id'  => $supplier->id,
        'currency'     => 'BDT',
        'items'        => [['product_id' => $product->id, 'qty_ordered' => 10, 'unit_price_local' => 50]],
    ]);
    $poItem = $po->fresh('items')->items->first();

    $grn = $inventory->createStockReceipt($po->id, [[
        'purchase_order_item_id' => $poItem->id,
        'qty_received'           => 10,
        'lot_number'             => 'LOT-A',
    ]]);
    $inventory->postStockReceipt($grn->id);

    $lot = Lot::where('product_id', $product->id)->where('lot_number', 'LOT-A')->firstOrFail();
    expect((float) $lot->qty_on_hand)->toBe(10.0);
    expect((float) $lot->qty_initial)->toBe(10.0);

    $inventory->voidStockReceipt($grn->id);

    expect((float) $lot->fresh()->qty_on_hand)->toBe(0.0);
    expect((float) WarehouseProduct::where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->value('qty_on_hand'))->toBe(0.0);
});

it('supports partial receiving across multiple GRNs before marking the purchase order received', function (): void {
    [$warehouse, $supplier, $product] = poFixtures('PARTIAL');
    $inventory = app(Inventory::class);

    $po = $inventory->createPurchaseOrder([
        'warehouse_id' => $warehouse->id,
        'supplier_id'  => $supplier->id,
        'currency'     => 'BDT',
        'items'        => [['product_id' => $product->id, 'qty_ordered' => 10, 'unit_price_local' => 100]],
    ]);
    $inventory->submitPurchaseOrder($po->id);
    $inventory->confirmPurchaseOrder($po->id);

    $itemId = $po->fresh('items')->items->first()->id;

    $po = $inventory->receivePurchaseOrder($po->id, [$itemId => 4]);
    expect($po->status)->toBe(PurchaseOrderStatus::PARTIAL);
    expect((float) $po->items->first()->qty_received)->toBe(4.0);

    $po = $inventory->receivePurchaseOrder($po->id, [$itemId => 6]);
    expect($po->status)->toBe(PurchaseOrderStatus::RECEIVED);
    expect((float) $po->items->first()->qty_received)->toBe(10.0);
});

it('rejects receiving more than the remaining quantity on a purchase order line', function (): void {
    [$warehouse, $supplier, $product] = poFixtures('OVER');
    $inventory = app(Inventory::class);

    $po = $inventory->createPurchaseOrder([
        'warehouse_id' => $warehouse->id,
        'supplier_id'  => $supplier->id,
        'currency'     => 'BDT',
        'items'        => [['product_id' => $product->id, 'qty_ordered' => 5, 'unit_price_local' => 100]],
    ]);
    $poItem = $po->fresh('items')->items->first();

    expect(fn () => $inventory->createStockReceipt($po->id, [[
        'purchase_order_item_id' => $poItem->id,
        'qty_received'           => 6,
    ]]))->toThrow(InvalidArgumentException::class);
});

it('rejects posting the same GRN twice', function (): void {
    [$warehouse, $supplier, $product] = poFixtures('DBLPOST');
    $inventory = app(Inventory::class);

    $po = $inventory->createPurchaseOrder([
        'warehouse_id' => $warehouse->id,
        'supplier_id'  => $supplier->id,
        'currency'     => 'BDT',
        'items'        => [['product_id' => $product->id, 'qty_ordered' => 5, 'unit_price_local' => 100]],
    ]);
    $poItem = $po->fresh('items')->items->first();

    $grn = $inventory->createStockReceipt($po->id, [[
        'purchase_order_item_id' => $poItem->id,
        'qty_received'           => 5,
    ]]);
    $inventory->postStockReceipt($grn->id);

    expect(fn () => $inventory->postStockReceipt($grn->id))->toThrow(InvalidTransitionException::class);

    // Stock must not have been double-counted by the rejected second post.
    expect((float) WarehouseProduct::where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->value('qty_on_hand'))->toBe(5.0);
});

it("reverses stock, WAC, and the purchase order's received quantity when voiding a posted GRN", function (): void {
    [$warehouse, $supplier, $product] = poFixtures('VOID');
    $inventory = app(Inventory::class);

    WarehouseProduct::create([
        'warehouse_id' => $warehouse->id,
        'product_id'   => $product->id,
        'qty_on_hand'  => 5,
        'wac_amount'   => 100,
    ]);

    $po = $inventory->createPurchaseOrder([
        'warehouse_id' => $warehouse->id,
        'supplier_id'  => $supplier->id,
        'currency'     => 'BDT',
        'items'        => [['product_id' => $product->id, 'qty_ordered' => 5, 'unit_price_local' => 100]],
    ]);
    $poItem = $po->fresh('items')->items->first();

    $grn = $inventory->createStockReceipt($po->id, [[
        'purchase_order_item_id' => $poItem->id,
        'qty_received'           => 5,
    ]]);
    $inventory->postStockReceipt($grn->id);

    $grn = $inventory->voidStockReceipt($grn->id);

    expect($grn->status)->toBe(StockReceiptStatus::VOID);
    expect((float) WarehouseProduct::where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->value('qty_on_hand'))->toBe(5.0);
    expect((float) $poItem->fresh()->qty_received)->toBe(0.0);
});

it('rejects voiding a GRN when the received stock was already sold off', function (): void {
    [$warehouse, $supplier, $product] = poFixtures('OVERSOLD');
    $inventory = app(Inventory::class);

    $po = $inventory->createPurchaseOrder([
        'warehouse_id' => $warehouse->id,
        'supplier_id'  => $supplier->id,
        'currency'     => 'BDT',
        'items'        => [['product_id' => $product->id, 'qty_ordered' => 5, 'unit_price_local' => 100]],
    ]);
    $poItem = $po->fresh('items')->items->first();

    $grn = $inventory->createStockReceipt($po->id, [[
        'purchase_order_item_id' => $poItem->id,
        'qty_received'           => 5,
    ]]);
    $inventory->postStockReceipt($grn->id);

    // Sell off the received stock through a separate adjustment write-off before the void.
    $wp = WarehouseProduct::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();
    $wp->update(['qty_on_hand' => 1]);

    expect(fn () => $inventory->voidStockReceipt($grn->id))->toThrow(InsufficientStockException::class);
});

it('rejects voiding the same GRN twice', function (): void {
    [$warehouse, $supplier, $product] = poFixtures('DBLVOID');
    $inventory = app(Inventory::class);

    $po = $inventory->createPurchaseOrder([
        'warehouse_id' => $warehouse->id,
        'supplier_id'  => $supplier->id,
        'currency'     => 'BDT',
        'items'        => [['product_id' => $product->id, 'qty_ordered' => 5, 'unit_price_local' => 100]],
    ]);
    $poItem = $po->fresh('items')->items->first();

    $grn = $inventory->createStockReceipt($po->id, [[
        'purchase_order_item_id' => $poItem->id,
        'qty_received'           => 5,
    ]]);
    $inventory->postStockReceipt($grn->id);
    $inventory->voidStockReceipt($grn->id);

    expect(fn () => $inventory->voidStockReceipt($grn->id))->toThrow(InvalidTransitionException::class);
});

it('rejects cancelling a purchase order that has already been received', function (): void {
    [$warehouse, $supplier, $product] = poFixtures('CANCELRCV');
    $inventory = app(Inventory::class);

    $po = $inventory->createPurchaseOrder([
        'warehouse_id' => $warehouse->id,
        'supplier_id'  => $supplier->id,
        'currency'     => 'BDT',
        'items'        => [['product_id' => $product->id, 'qty_ordered' => 5, 'unit_price_local' => 100]],
    ]);
    $inventory->submitPurchaseOrder($po->id);
    $inventory->confirmPurchaseOrder($po->id);
    $inventory->receivePurchaseOrder($po->id);

    expect(fn () => $inventory->cancelPurchaseOrder($po->id))->toThrow(InvalidTransitionException::class);
});

it('validates a purchase return against a purchase order does not exceed the received quantity', function (): void {
    [$warehouse, $supplier, $product] = poFixtures('RETVALID');
    $inventory = app(Inventory::class);

    $po = $inventory->createPurchaseOrder([
        'warehouse_id' => $warehouse->id,
        'supplier_id'  => $supplier->id,
        'currency'     => 'BDT',
        'items'        => [['product_id' => $product->id, 'qty_ordered' => 10, 'unit_price_local' => 100]],
    ]);
    $inventory->submitPurchaseOrder($po->id);
    $inventory->confirmPurchaseOrder($po->id);
    $inventory->receivePurchaseOrder($po->id, options: []);

    expect(fn () => $inventory->createPurchaseReturn([
        'purchase_order_id' => $po->id,
        'warehouse_id'      => $warehouse->id,
        'items'             => [['product_id' => $product->id, 'qty_returned' => 11]],
    ]))->toThrow(Illuminate\Validation\ValidationException::class);
});

it('posts a purchase return and decrements stock, rejecting a return that exceeds available stock', function (): void {
    [$warehouse, $supplier, $product] = poFixtures('RETPOST');
    $inventory = app(Inventory::class);

    $po = $inventory->createPurchaseOrder([
        'warehouse_id' => $warehouse->id,
        'supplier_id'  => $supplier->id,
        'currency'     => 'BDT',
        'items'        => [['product_id' => $product->id, 'qty_ordered' => 10, 'unit_price_local' => 100]],
    ]);
    $inventory->submitPurchaseOrder($po->id);
    $inventory->confirmPurchaseOrder($po->id);
    $inventory->receivePurchaseOrder($po->id);

    $return = $inventory->createPurchaseReturn([
        'purchase_order_id' => $po->id,
        'warehouse_id'      => $warehouse->id,
        'items'             => [['product_id' => $product->id, 'qty_returned' => 4]],
    ]);
    $return = $inventory->postPurchaseReturn($return->id);

    expect($return->status)->toBe('posted');
    expect((float) WarehouseProduct::where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->value('qty_on_hand'))->toBe(6.0);

    // A second return against the same PO for the remaining receivable quantity is fine...
    $secondReturn = $inventory->createPurchaseReturn([
        'purchase_order_id' => $po->id,
        'warehouse_id'      => $warehouse->id,
        'items'             => [['product_id' => $product->id, 'qty_returned' => 6]],
    ]);

    // ...but posting it after stock has since been depleted below the return quantity must fail.
    WarehouseProduct::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->update(['qty_on_hand' => 1]);

    expect(fn () => $inventory->postPurchaseReturn($secondReturn->id))->toThrow(InsufficientStockException::class);
});

it('creates a standalone purchase return without a linked purchase order', function (): void {
    [$warehouse, $supplier, $product] = poFixtures('RETSTANDALONE');
    $inventory = app(Inventory::class);

    WarehouseProduct::create([
        'warehouse_id' => $warehouse->id,
        'product_id'   => $product->id,
        'qty_on_hand'  => 8,
        'wac_amount'   => 90,
    ]);

    $return = $inventory->createPurchaseReturn([
        'warehouse_id' => $warehouse->id,
        'supplier_id'  => $supplier->id,
        'items'        => [['product_id' => $product->id, 'qty_returned' => 3, 'unit_cost_amount' => 90]],
    ]);

    expect($return->purchase_order_id)->toBeNull();
    expect($return->supplier_id)->toBe($supplier->id);

    $inventory->postPurchaseReturn($return->id);

    expect((float) WarehouseProduct::where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->value('qty_on_hand'))->toBe(5.0);
});

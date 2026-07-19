<?php

declare(strict_types = 1);

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Customer, Product, SaleOrder, SaleOrderItem, StockReceipt, StockReceiptItem, Supplier, Warehouse, WarehouseProduct};
use Illuminate\Support\Carbon;

function makeAgingWarehouseAndProduct(string $suffix): array
{
    $warehouse = Warehouse::create([
        'code'         => "W-AGING-{$suffix}",
        'name'         => "Aging Warehouse {$suffix}",
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $supplier = Supplier::create([
        'code'     => "SUP-AGING-{$suffix}",
        'name'     => "Aging Supplier {$suffix}",
        'currency' => 'BDT',
    ]);
    $product = Product::create([
        'sku'          => "SKU-AGING-{$suffix}",
        'name'         => "Aging Widget {$suffix}",
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);

    return [$warehouse, $supplier, $product];
}

function receiveAgingStock(Inventory $inventory, Warehouse $warehouse, Supplier $supplier, Product $product, float $qty): void
{
    $po = $inventory->createPurchaseOrder([
        'warehouse_id' => $warehouse->id,
        'supplier_id'  => $supplier->id,
        'currency'     => 'BDT',
        'items'        => [[
            'product_id'       => $product->id,
            'qty_ordered'      => $qty,
            'unit_price_local' => 100,
        ]],
    ]);

    $receipt = $inventory->createStockReceipt($po->id, [[
        'purchase_order_item_id' => $po->fresh('items')->items->first()->id,
        'qty_received'           => $qty,
    ]]);

    $inventory->postStockReceipt($receipt->id);
}

/**
 * Creates a posted GRN + item directly (bypassing Inventory::createStockReceipt()/
 * postStockReceipt(), so no inv_stock_movements row is written) — simulates a purchase
 * that was carried over from a previous system without its derived movement history.
 */
function migrateInAgingReceipt(Warehouse $warehouse, Product $product, float $qty, Carbon $receivedAt): StockReceiptItem
{
    $receipt = StockReceipt::create([
        'grn_number'   => 'GRN-MIGRATED-' . uniqid(),
        'warehouse_id' => $warehouse->id,
        'received_at'  => $receivedAt,
        'status'       => 'posted',
    ]);

    return StockReceiptItem::create([
        'stock_receipt_id' => $receipt->id,
        'product_id'       => $product->id,
        'qty_received'     => $qty,
        'unit_cost_local'  => 100,
        'unit_cost_amount' => 100,
        'exchange_rate'    => 1,
    ]);
}

/**
 * Creates a fulfilled sale-order item directly (bypassing Inventory::fulfillSaleOrder(), so
 * no inv_stock_movements row is written) — simulates a sale carried over the same way.
 */
function migrateInAgingSale(Warehouse $warehouse, Product $product, float $qty, Carbon $orderedAt): SaleOrderItem
{
    $customer = Customer::create([
        'code'            => 'CUS-AGING-' . uniqid(),
        'name'            => 'Aging Customer',
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'is_active'       => true,
    ]);

    $saleOrder = SaleOrder::create([
        'so_number'       => 'SO-MIGRATED-' . uniqid(),
        'document_type'   => 'order',
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customer->id,
        'price_tier_code' => 'b2c_retail',
        'currency'        => 'BDT',
        'exchange_rate'   => 1,
        'total_local'     => $qty * 150,
        'total_amount'    => $qty * 150,
        'status'          => 'fulfilled',
        'ordered_at'      => $orderedAt,
    ]);

    return SaleOrderItem::create([
        'sale_order_id'     => $saleOrder->id,
        'product_id'        => $product->id,
        'price_tier_code'   => 'b2c_retail',
        'qty_ordered'       => $qty,
        'qty_fulfilled'     => $qty,
        'unit_price_local'  => 150,
        'unit_price_amount' => 150,
        'line_total_local'  => $qty * 150,
        'line_total_amount' => $qty * 150,
    ]);
}

afterEach(function (): void {
    Carbon::setTestNow();
});

it('ages each receipt separately via FIFO instead of aging everything from the latest one', function (): void {
    $inventory = app(Inventory::class);
    [$warehouse, $supplier, $product] = makeAgingWarehouseAndProduct('1');

    $now = Carbon::parse('2026-07-20 12:00:00');

    Carbon::setTestNow($now->copy()->subDays(95));
    receiveAgingStock($inventory, $warehouse, $supplier, $product, 100);

    Carbon::setTestNow($now->copy()->subDays(5));
    receiveAgingStock($inventory, $warehouse, $supplier, $product, 50);

    Carbon::setTestNow($now);

    $row = $inventory->stockAgingReport($warehouse->id)->firstOrFail();

    expect($row['qty_on_hand'])->toBe(150.0)
        ->and($row['buckets']['90+']['qty'])->toBe(100.0)
        ->and($row['buckets']['0-30']['qty'])->toBe(50.0)
        ->and($row['buckets']['31-60']['qty'])->toBe(0.0)
        ->and($row['buckets']['61-90']['qty'])->toBe(0.0)
        ->and($row['oldest_days_in_stock'])->toBe(95);
});

it('consumes the oldest batch first when stock is sold, aging what survives correctly', function (): void {
    $inventory = app(Inventory::class);
    [$warehouse, $supplier, $product] = makeAgingWarehouseAndProduct('2');

    $now = Carbon::parse('2026-07-20 12:00:00');

    Carbon::setTestNow($now->copy()->subDays(95));
    receiveAgingStock($inventory, $warehouse, $supplier, $product, 100);

    Carbon::setTestNow($now->copy()->subDays(5));
    receiveAgingStock($inventory, $warehouse, $supplier, $product, 50);

    Carbon::setTestNow($now->copy()->subDays(1));

    $saleOrder = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [[
            'product_id'       => $product->id,
            'qty_ordered'      => 80,
            'unit_price_local' => 150,
        ]],
    ]);
    $inventory->confirmSaleOrder($saleOrder->id);
    $inventory->reserveStock($saleOrder->id);
    $itemId = $saleOrder->fresh('items')->items->first()->id;
    $inventory->fulfillSaleOrder($saleOrder->id, [$itemId => 80]);

    Carbon::setTestNow($now);

    $row = $inventory->stockAgingReport($warehouse->id)->firstOrFail();

    // 80 of the 100 units received 95 days ago were sold (FIFO) — 20 of that old batch
    // survive, plus the full 50-unit batch received 5 days ago is untouched.
    expect($row['qty_on_hand'])->toBe(70.0)
        ->and($row['buckets']['90+']['qty'])->toBe(20.0)
        ->and($row['buckets']['0-30']['qty'])->toBe(50.0)
        ->and($row['oldest_days_in_stock'])->toBe(95);
});

it('attributes qty_on_hand with no traceable movement to the unknown bucket', function (): void {
    $inventory = app(Inventory::class);
    [$warehouse, , $product] = makeAgingWarehouseAndProduct('3');

    // Simulates stock that predates movement tracking (e.g. a legacy opening balance
    // written directly to the ledger row, with no inv_stock_movements history at all).
    WarehouseProduct::create([
        'warehouse_id' => $warehouse->id,
        'product_id'   => $product->id,
        'qty_on_hand'  => 25,
        'wac_amount'   => 50,
    ]);

    $row = $inventory->stockAgingReport($warehouse->id)->firstOrFail();

    expect($row['buckets']['unknown']['qty'])->toBe(25.0)
        ->and($row['oldest_days_in_stock'])->toBeNull();
});

it('backfills stock aging from posted GRNs when the movement ledger is missing', function (): void {
    $inventory = app(Inventory::class);
    [$warehouse, , $product] = makeAgingWarehouseAndProduct('4');

    $now = Carbon::parse('2026-07-20 12:00:00');

    // A GRN carried over from a previous system with no inv_stock_movements history at all.
    migrateInAgingReceipt($warehouse, $product, 100, $now->copy()->subDays(95));

    WarehouseProduct::create([
        'warehouse_id' => $warehouse->id,
        'product_id'   => $product->id,
        'qty_on_hand'  => 100,
        'wac_amount'   => 100,
    ]);

    Carbon::setTestNow($now);

    $row = $inventory->stockAgingReport($warehouse->id)->firstOrFail();

    expect($row['qty_on_hand'])->toBe(100.0)
        ->and($row['buckets']['90+']['qty'])->toBe(100.0)
        ->and($row['buckets']['unknown']['qty'])->toBe(0.0)
        ->and($row['oldest_days_in_stock'])->toBe(95);
});

it('backfills stock aging from fulfilled sale-order items when the movement ledger is missing', function (): void {
    $inventory = app(Inventory::class);
    [$warehouse, , $product] = makeAgingWarehouseAndProduct('5');

    $now = Carbon::parse('2026-07-20 12:00:00');

    migrateInAgingReceipt($warehouse, $product, 100, $now->copy()->subDays(95));
    migrateInAgingReceipt($warehouse, $product, 50, $now->copy()->subDays(5));
    migrateInAgingSale($warehouse, $product, 80, $now->copy()->subDays(1));

    WarehouseProduct::create([
        'warehouse_id' => $warehouse->id,
        'product_id'   => $product->id,
        'qty_on_hand'  => 70, // 100 + 50 - 80, mirroring what the real flow would have left
        'wac_amount'   => 100,
    ]);

    Carbon::setTestNow($now);

    $row = $inventory->stockAgingReport($warehouse->id)->firstOrFail();

    // Purely reconstructed from documents (no movement rows at all): the sale consumes the
    // oldest receipt first, same as when the movement ledger is present.
    expect($row['qty_on_hand'])->toBe(70.0)
        ->and($row['buckets']['90+']['qty'])->toBe(20.0)
        ->and($row['buckets']['0-30']['qty'])->toBe(50.0)
        ->and($row['buckets']['unknown']['qty'])->toBe(0.0)
        ->and($row['oldest_days_in_stock'])->toBe(95);
});

it('does not double count a receipt that already has a real movement row', function (): void {
    $inventory = app(Inventory::class);
    [$warehouse, $supplier, $product] = makeAgingWarehouseAndProduct('6');

    $now = Carbon::parse('2026-07-20 12:00:00');

    // Received through the real flow (writes an inv_stock_movements row) 95 days ago...
    Carbon::setTestNow($now->copy()->subDays(95));
    receiveAgingStock($inventory, $warehouse, $supplier, $product, 100);

    // ...plus a second GRN carried over from the migration, with no movement row, 5 days ago.
    migrateInAgingReceipt($warehouse, $product, 50, $now->copy()->subDays(5));
    WarehouseProduct::where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->update(['qty_on_hand' => 150]);

    Carbon::setTestNow($now);

    $row = $inventory->stockAgingReport($warehouse->id)->firstOrFail();

    // 150, not 200 — the first receipt must not be counted twice just because a second,
    // movement-less receipt for the same product also exists.
    expect($row['qty_on_hand'])->toBe(150.0)
        ->and($row['buckets']['90+']['qty'])->toBe(100.0)
        ->and($row['buckets']['0-30']['qty'])->toBe(50.0)
        ->and($row['buckets']['unknown']['qty'])->toBe(0.0);
});

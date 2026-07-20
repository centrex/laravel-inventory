<?php

declare(strict_types = 1);

use Centrex\Inventory\Enums\MovementType;
use Centrex\Inventory\Models\{Product, StockMovement, Warehouse, WarehouseProduct};

function makeBackfillWarehouseAndProduct(string $suffix): array
{
    $warehouse = Warehouse::create([
        'code'         => "W-BACKFILL-{$suffix}",
        'name'         => "Backfill Warehouse {$suffix}",
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $product = Product::create([
        'sku'          => "SKU-BACKFILL-{$suffix}",
        'name'         => "Backfill Widget {$suffix}",
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);

    return [$warehouse, $product];
}

it('creates an opening-stock movement for a warehouse product with no movement history', function (): void {
    [$warehouse, $product] = makeBackfillWarehouseAndProduct('A');

    $warehouseProduct = WarehouseProduct::create([
        'warehouse_id' => $warehouse->id,
        'product_id'   => $product->id,
        'qty_on_hand'  => 42,
        'wac_amount'   => 15.5,
    ]);

    expect(StockMovement::where('product_id', $product->id)->count())->toBe(0);

    $this->artisan('inventory:backfill-movements')->assertExitCode(0);

    $movement = StockMovement::where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->first();

    expect($movement)->not->toBeNull()
        ->and($movement->movement_type)->toBe(MovementType::OPENING_STOCK)
        ->and($movement->direction)->toBe('in')
        ->and((float) $movement->qty)->toBe(42.0)
        ->and((float) $movement->qty_before)->toBe(0.0)
        ->and((float) $movement->qty_after)->toBe(42.0)
        ->and((float) $movement->wac_amount)->toBe(15.5);
});

it('is idempotent — running it twice does not duplicate movements', function (): void {
    [$warehouse, $product] = makeBackfillWarehouseAndProduct('B');

    WarehouseProduct::create([
        'warehouse_id' => $warehouse->id,
        'product_id'   => $product->id,
        'qty_on_hand'  => 10,
    ]);

    $this->artisan('inventory:backfill-movements')->assertExitCode(0);
    $this->artisan('inventory:backfill-movements')->assertExitCode(0);

    expect(StockMovement::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->count())->toBe(1);
});

it('skips warehouse products that already have movement history', function (): void {
    [$warehouse, $product] = makeBackfillWarehouseAndProduct('C');

    WarehouseProduct::create([
        'warehouse_id' => $warehouse->id,
        'product_id'   => $product->id,
        'qty_on_hand'  => 5,
    ]);

    StockMovement::create([
        'warehouse_id'  => $warehouse->id,
        'product_id'    => $product->id,
        'movement_type' => MovementType::PURCHASE_RECEIPT,
        'direction'     => 'in',
        'qty'           => 5,
        'qty_before'    => 0,
        'qty_after'     => 5,
        'moved_at'      => now(),
    ]);

    $this->artisan('inventory:backfill-movements')->assertExitCode(0);

    expect(StockMovement::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->count())->toBe(1);
});

it('does not persist anything in dry-run mode', function (): void {
    [$warehouse, $product] = makeBackfillWarehouseAndProduct('D');

    WarehouseProduct::create([
        'warehouse_id' => $warehouse->id,
        'product_id'   => $product->id,
        'qty_on_hand'  => 7,
    ]);

    $this->artisan('inventory:backfill-movements', ['--dry-run' => true])->assertExitCode(0);

    expect(StockMovement::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->count())->toBe(0);
});

it('scopes to a single warehouse when --warehouse is passed', function (): void {
    [$warehouseA, $productA] = makeBackfillWarehouseAndProduct('E1');
    [$warehouseB, $productB] = makeBackfillWarehouseAndProduct('E2');

    WarehouseProduct::create(['warehouse_id' => $warehouseA->id, 'product_id' => $productA->id, 'qty_on_hand' => 3]);
    WarehouseProduct::create(['warehouse_id' => $warehouseB->id, 'product_id' => $productB->id, 'qty_on_hand' => 3]);

    $this->artisan('inventory:backfill-movements', ['--warehouse' => $warehouseA->id])->assertExitCode(0);

    expect(StockMovement::where('warehouse_id', $warehouseA->id)->count())->toBe(1)
        ->and(StockMovement::where('warehouse_id', $warehouseB->id)->count())->toBe(0);
});

it('skips rows with a negative on-hand quantity without erroring', function (): void {
    [$warehouse, $product] = makeBackfillWarehouseAndProduct('F');

    WarehouseProduct::create([
        'warehouse_id' => $warehouse->id,
        'product_id'   => $product->id,
        'qty_on_hand'  => -3,
    ]);

    $this->artisan('inventory:backfill-movements')->assertExitCode(0);

    expect(StockMovement::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->count())->toBe(0);
});

it('ignores warehouse products with zero on-hand quantity', function (): void {
    [$warehouse, $product] = makeBackfillWarehouseAndProduct('G');

    WarehouseProduct::create([
        'warehouse_id' => $warehouse->id,
        'product_id'   => $product->id,
        'qty_on_hand'  => 0,
    ]);

    $this->artisan('inventory:backfill-movements')->assertExitCode(0);

    expect(StockMovement::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->count())->toBe(0);
});

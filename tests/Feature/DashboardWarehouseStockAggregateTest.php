<?php

declare(strict_types = 1);

use Centrex\Inventory\Models\{Product, Warehouse, WarehouseProduct};

/**
 * Regression test for InventoryWarehouseStockCard's grouped warehouse-stock query
 * (introduced to fix the getStockValue()/getNetSaleableStock() N+1). The raw SELECT must
 * not alias a column as "net_saleable_stock" — WarehouseProduct::getNetSaleableStockAttribute()
 * is a real accessor, and Eloquent calls that accessor instead of returning the raw-selected
 * value, throwing MissingAttributeException reaching for qty_on_hand/qty_reserved (columns
 * this aggregate query never selects).
 */
it('groups warehouse stock without tripping the getNetSaleableStockAttribute() accessor', function (): void {
    $warehouse = Warehouse::create([
        'code' => 'W-AGG-1', 'name' => 'Aggregate Test WH', 'country_code' => 'BD', 'currency' => 'BDT',
    ]);

    $product = Product::create(['sku' => 'SKU-AGG-1', 'name' => 'Aggregate Test Product', 'unit' => 'pcs']);

    WarehouseProduct::create([
        'warehouse_id' => $warehouse->id,
        'product_id'   => $product->id,
        'qty_on_hand'  => 100,
        'qty_reserved' => 20,
        'wac_amount'   => 10,
    ]);

    $totals = WarehouseProduct::query()
        ->selectRaw('warehouse_id, SUM(qty_on_hand * wac_amount) as agg_stock_value, SUM(qty_on_hand - qty_reserved) as agg_net_saleable_stock')
        ->groupBy('warehouse_id')
        ->get()
        ->keyBy('warehouse_id');

    $row = $totals->get($warehouse->id);

    expect((float) $row->agg_stock_value)->toBe(1000.0)
        ->and((float) $row->agg_net_saleable_stock)->toBe(80.0);
});

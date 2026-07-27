<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Entities\WarehouseStockTable;
use Centrex\Inventory\Models\{Product, Warehouse, WarehouseProduct};

it('routes the sku column search through the product/variant relation instead of a bare column', function (): void {
    $table = new WarehouseStockTable();
    $query = $table->query();

    $method = new ReflectionMethod($table, 'applySearchConstraint');
    $method->setAccessible(true);
    $method->invoke($table, $query, 'sku', 'ogx');

    $sql = $query->toSql();

    expect($sql)->not->toContain('`sku`')
        ->and($sql)->toContain('exists');
});

it('merges sku into the product column and shows a b2b retail price column', function (): void {
    $keys = collect((new WarehouseStockTable())->columns())
        ->map(fn ($column) => $column->toArray()['key'])
        ->all();

    expect($keys)->not->toContain('sku')
        ->and($keys)->toContain('b2b_retail_price')
        ->and($keys)->toContain('product.name');
});

it('computes net saleable stock as qty_on_hand minus qty_reserved via a sortable SQL column', function (): void {
    $warehouse = Warehouse::create([
        'code'         => 'W-NETSALE',
        'name'         => 'Net Sale Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $product = Product::create([
        'sku'          => 'SKU-NETSALE',
        'name'         => 'Net Sale Widget',
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);
    WarehouseProduct::create([
        'warehouse_id'   => $warehouse->id,
        'product_id'     => $product->id,
        'qty_on_hand'    => 20,
        'qty_reserved'   => 6,
        'qty_in_transit' => 0,
        'wac_amount'     => 10,
    ]);

    $table = new WarehouseStockTable();
    $row = $table->query()->where('warehouse_id', $warehouse->id)->firstOrFail();

    // Selected as a SQL alias so ->orderBy('net_saleable_stock') works; the model's own
    // getNetSaleableStockAttribute() accessor computes the same figure on access.
    expect((float) $row->net_saleable_stock)->toBe(14.0);

    // Also sortable at the SQL level (not just via the PHP accessor) — required for
    // ->sortable() on the column, since DataTable sorts with ->orderBy() on the query.
    $ordered = $table->query()->orderBy('net_saleable_stock')->pluck('net_saleable_stock');

    expect($ordered->values()->first())->toBeLessThanOrEqual($ordered->values()->last());
});

<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Entities\WarehouseStockTable;

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

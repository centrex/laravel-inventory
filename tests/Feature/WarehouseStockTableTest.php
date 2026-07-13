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

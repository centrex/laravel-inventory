<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Entities\ProductPriceTable;

it('routes the product column search through product and variant sku', function (): void {
    $table = new ProductPriceTable();
    $query = $table->query();

    $method = new ReflectionMethod($table, 'applySearchConstraint');
    $method->setAccessible(true);
    $method->invoke($table, $query, 'product.name', 'ogx');

    $sql = $query->toSql();

    expect($sql)->toContain('exists')
        ->and($sql)->toContain('sku');
});

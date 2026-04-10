<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Concerns;

trait AddTablePrefix
{
    public function getTable(): string
    {
        $prefix = config('inventory.table_prefix') ?: 'inv_';

        return $prefix . $this->getTableSuffix();
    }

    abstract protected function getTableSuffix(): string;
}

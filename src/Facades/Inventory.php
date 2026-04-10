<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Centrex\Inventory\Inventory
 */
class Inventory extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Centrex\Inventory\Inventory::class;
    }
}

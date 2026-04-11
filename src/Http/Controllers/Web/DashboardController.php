<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Controllers\Web;

use Centrex\Inventory\Support\InventoryEntityRegistry;
use Illuminate\Contracts\View\View;

class DashboardController
{
    public function __invoke(): View
    {
        return view('inventory::dashboard', [
            'entities' => InventoryEntityRegistry::entities(),
        ]);
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Controllers\Web;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\Warehouse;
use Centrex\Inventory\Support\InventoryEntityRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

class DashboardController
{
    public function __invoke(): View
    {
        $inventory = app(Inventory::class);
        $canViewForecast = Gate::allows('inventory.reports.view');
        $forecast = $canViewForecast ? $inventory->salesForecast() : null;
        $warehouseStockValues = Warehouse::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Warehouse $warehouse) => [
                'id'          => $warehouse->id,
                'name'        => $warehouse->name,
                'currency'    => $warehouse->currency,
                'stock_value' => $inventory->getStockValue($warehouse->id),
            ]);

        return view('inventory::dashboard', [
            'entities'             => InventoryEntityRegistry::entities(),
            'warehouseStockValues' => $warehouseStockValues,
            'totalStockValue'      => $warehouseStockValues->sum('stock_value'),
            'forecast'             => $forecast,
            'canViewForecast'      => $canViewForecast,
        ]);
    }
}

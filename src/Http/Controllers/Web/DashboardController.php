<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Controllers\Web;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\Warehouse;
use Centrex\Inventory\Support\InventoryEntityRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController
{
    public function __invoke(Request $request): View
    {
        $inventory = app(Inventory::class);
        $canViewForecast = Gate::allows('inventory.reports.view');
        $forecast = $canViewForecast ? $inventory->salesForecast() : null;
        $salesTarget = $canViewForecast ? $inventory->salesTarget(
            lookbackDays: $request->integer('target_lookback_days', 90),
            targetDays: $request->integer('target_days', 30),
            expectedGrossMarginPct: $request->filled('target_gross_margin_pct') ? (float) $request->input('target_gross_margin_pct') : null,
            desiredNetMarginPct: $request->filled('target_net_margin_pct') ? (float) $request->input('target_net_margin_pct') : 10.0,
            growthPct: $request->filled('target_growth_pct') ? (float) $request->input('target_growth_pct') : 0.0,
            expenseAllocationPct: $request->filled('target_expense_allocation_pct') ? (float) $request->input('target_expense_allocation_pct') : null,
        ) : null;
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
            'salesTarget'          => $salesTarget,
            'canViewForecast'      => $canViewForecast,
        ]);
    }
}

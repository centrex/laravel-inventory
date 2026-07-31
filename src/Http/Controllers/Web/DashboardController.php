<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Controllers\Web;

use Centrex\Inventory\Support\InventoryEntityRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController
{
    public function __invoke(Request $request): View
    {
        Gate::authorize('inventory.master-data.view');
        $canViewForecast = Gate::allows('inventory.reports.view');

        // Forecast/Sales Target used to be computed here on every dashboard load regardless
        // of which tab was open — salesForecast() alone is the heaviest computation in the
        // codebase (full order/item/warehouse-product/PO-item scans). Both now live in their
        // own lazy Livewire components (InventoryForecastCard, InventorySalesTargetCard),
        // mounted only once their tab is actually opened — see dashboard.blade.php.

        // The Overview tab's report cards (Sales Order Trend, Draft Sale Orders, Sales by
        // Price Tier, Sales by Employee, Warehouse Stock) used to be computed here too, on
        // every dashboard load. They're each their own lazy Livewire component now
        // (InventorySalesTrendCard and friends) — the controller only needs to authorize
        // the page and hand over the Master Data entity list.

        return view('inventory::dashboard', [
            'entities'        => InventoryEntityRegistry::entities(),
            'canViewForecast' => $canViewForecast,
        ]);
    }
}

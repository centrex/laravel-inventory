<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Controllers\Web;

use Centrex\Inventory\Enums\SaleOrderStatus;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{SaleOrder, Warehouse};
use Centrex\Inventory\Support\InventoryEntityRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController
{
    public function __invoke(Request $request): View
    {
        Gate::authorize('inventory.master-data.view');
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
            'salesTrend'           => $this->buildSalesTrend(),
        ]);
    }

    private function buildSalesTrend(): array
    {
        $thisStart = now()->startOfMonth();
        $thisEnd = now()->endOfDay();
        $prevStart = now()->subMonthNoOverflow()->startOfMonth();
        $prevEnd = now()->subMonthNoOverflow()->endOfMonth();

        $excluded = [SaleOrderStatus::CANCELLED->value, SaleOrderStatus::RETURNED->value];

        $thisStats = SaleOrder::query()
            ->whereBetween('ordered_at', [$thisStart, $thisEnd])
            ->whereNotIn('status', $excluded)
            ->selectRaw('COUNT(*) as orders_count, COALESCE(SUM(total_amount), 0) as revenue, COALESCE(SUM(cogs_amount), 0) as cogs')
            ->first();

        $prevStats = SaleOrder::query()
            ->whereBetween('ordered_at', [$prevStart, $prevEnd])
            ->whereNotIn('status', $excluded)
            ->selectRaw('COUNT(*) as orders_count, COALESCE(SUM(total_amount), 0) as revenue, COALESCE(SUM(cogs_amount), 0) as cogs')
            ->first();

        $thisDailyRevenue = SaleOrder::query()
            ->whereBetween('ordered_at', [$thisStart, $thisEnd])
            ->whereNotIn('status', $excluded)
            ->selectRaw('DATE(ordered_at) as date, COALESCE(SUM(total_amount), 0) as revenue')
            ->groupBy('date')
            ->pluck('revenue', 'date');

        $prevDailyRevenue = SaleOrder::query()
            ->whereBetween('ordered_at', [$prevStart, $prevEnd])
            ->whereNotIn('status', $excluded)
            ->selectRaw('DATE(ordered_at) as date, COALESCE(SUM(total_amount), 0) as revenue')
            ->groupBy('date')
            ->pluck('revenue', 'date');

        $daysInMonth = now()->daysInMonth;
        $days = range(1, $daysInMonth);

        $thisRevArray = array_fill_keys($days, 0.0);

        foreach ($thisDailyRevenue as $date => $revenue) {
            $day = (int) date('j', strtotime((string) $date));

            if (array_key_exists($day, $thisRevArray)) {
                $thisRevArray[$day] = round((float) $revenue, 2);
            }
        }

        $prevRevArray = array_fill_keys($days, 0.0);

        foreach ($prevDailyRevenue as $date => $revenue) {
            $day = (int) date('j', strtotime((string) $date));

            if (array_key_exists($day, $prevRevArray)) {
                $prevRevArray[$day] = round((float) $revenue, 2);
            }
        }

        $thisOrders = (int) ($thisStats->orders_count ?? 0);
        $prevOrders = (int) ($prevStats->orders_count ?? 0);
        $thisRevenue = (float) ($thisStats->revenue ?? 0);
        $prevRevenue = (float) ($prevStats->revenue ?? 0);
        $thisGrossProfit = $thisRevenue - (float) ($thisStats->cogs ?? 0);
        $prevGrossProfit = $prevRevenue - (float) ($prevStats->cogs ?? 0);

        $pctChange = static fn (float $current, float $previous): ?float => $previous != 0.0 ? round(($current - $previous) / abs($previous) * 100, 1) : null;

        return [
            'this_month' => [
                'label'        => now()->format('M Y'),
                'orders_count' => $thisOrders,
                'revenue'      => $thisRevenue,
                'gross_profit' => $thisGrossProfit,
            ],
            'prev_month' => [
                'label'        => now()->subMonthNoOverflow()->format('M Y'),
                'orders_count' => $prevOrders,
                'revenue'      => $prevRevenue,
                'gross_profit' => $prevGrossProfit,
            ],
            'change' => [
                'orders_count' => $pctChange($thisOrders, $prevOrders),
                'revenue'      => $pctChange($thisRevenue, $prevRevenue),
                'gross_profit' => $pctChange($thisGrossProfit, $prevGrossProfit),
            ],
            'chart' => [
                'categories' => array_map(static fn (int $d): string => (string) $d, $days),
                'series'     => [
                    ['name' => now()->format('M'), 'data' => array_values($thisRevArray)],
                    ['name' => now()->subMonthNoOverflow()->format('M'), 'data' => array_values($prevRevArray)],
                ],
            ],
        ];
    }
}

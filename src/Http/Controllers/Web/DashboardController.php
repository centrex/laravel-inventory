<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Controllers\Web;

use Centrex\Inventory\Enums\SaleOrderStatus;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{SaleOrder, Warehouse};
use Centrex\Inventory\Support\{CommercialTeamAccess, InventoryEntityRegistry};
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
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

        // Scoped to the current user's own orders plus their reporting line (via
        // CommercialTeamAccess), same rule the dispatch terminal and order lists use —
        // an executive sees their own numbers, a manager sees their whole team's.
        $scopedOrders = static fn (): \Illuminate\Database\Eloquent\Builder => CommercialTeamAccess::applySalesScope(
            SaleOrder::query()->where('document_type', 'order'),
        );

        $thisOrders = $scopedOrders()
            ->whereBetween('ordered_at', [$thisStart, $thisEnd])
            ->whereNotIn('status', $excluded)
            ->get(['id', 'total_amount', 'cogs_amount', 'accounting_invoice_id', 'ordered_at']);

        $prevOrders = $scopedOrders()
            ->whereBetween('ordered_at', [$prevStart, $prevEnd])
            ->whereNotIn('status', $excluded)
            ->get(['id', 'total_amount', 'cogs_amount', 'accounting_invoice_id', 'ordered_at']);

        $thisSummary = $this->summarizeOrders($thisOrders);
        $prevSummary = $this->summarizeOrders($prevOrders);

        $daysInMonth = now()->daysInMonth;
        $days = range(1, $daysInMonth);

        $fillDaily = static function (Collection $orders) use ($days): array {
            $byDay = array_fill_keys($days, 0.0);

            foreach ($orders->groupBy(static fn (SaleOrder $order): int => (int) ($order->ordered_at?->format('j') ?? 0)) as $day => $group) {
                if (array_key_exists((int) $day, $byDay)) {
                    $byDay[(int) $day] = round((float) $group->sum('total_amount'), 2);
                }
            }

            return array_values($byDay);
        };

        $pctChange = static fn (float $current, float $previous): ?float => $previous != 0.0 ? round(($current - $previous) / abs($previous) * 100, 1) : null;

        // Live dispatch pipeline — orders currently sent to courier (Shipped) and not yet
        // delivered. This is a snapshot, not a monthly comparison, so it isn't part of the
        // this/prev-month trend above.
        $dispatchedCount = $scopedOrders()->where('status', SaleOrderStatus::SHIPPED->value)->count();

        return [
            'scope_label' => CommercialTeamAccess::visibleUserIds('sales') === null ? 'Company-wide' : 'You & your team',
            'this_month'  => [
                'label'          => now()->format('M Y'),
                'orders_count'   => $thisSummary['orders_count'],
                'revenue'        => $thisSummary['revenue'],
                'net_profit'     => $thisSummary['net_profit'],
                'net_margin_pct' => $thisSummary['net_margin_pct'],
            ],
            'prev_month' => [
                'label'          => now()->subMonthNoOverflow()->format('M Y'),
                'orders_count'   => $prevSummary['orders_count'],
                'revenue'        => $prevSummary['revenue'],
                'net_profit'     => $prevSummary['net_profit'],
                'net_margin_pct' => $prevSummary['net_margin_pct'],
            ],
            'change' => [
                'orders_count' => $pctChange($thisSummary['orders_count'], $prevSummary['orders_count']),
                'revenue'      => $pctChange($thisSummary['revenue'], $prevSummary['revenue']),
                'net_profit'   => $pctChange($thisSummary['net_profit'], $prevSummary['net_profit']),
            ],
            'dispatched_count' => $dispatchedCount,
            'chart'            => [
                'categories' => array_map(static fn (int $d): string => (string) $d, $days),
                'series'     => [
                    ['name' => now()->format('M'), 'data' => $fillDaily($thisOrders)],
                    ['name' => now()->subMonthNoOverflow()->format('M'), 'data' => $fillDaily($prevOrders)],
                ],
            ],
        ];
    }

    /**
     * @param  Collection<int, SaleOrder>  $orders
     */
    private function summarizeOrders(Collection $orders): array
    {
        $revenue = (float) $orders->sum('total_amount');
        $cogs = (float) $orders->sum('cogs_amount');

        $invoiceIds = $orders->pluck('accounting_invoice_id')->filter()->unique()->values()->map(static fn ($id): int => (int) $id)->all();
        $deductions = $this->salesDeductions($invoiceIds);
        $netProfit = $revenue - $cogs - $deductions['discount'] - $deductions['charges'];

        return [
            'orders_count'   => $orders->count(),
            'revenue'        => $revenue,
            'net_profit'     => $netProfit,
            'net_margin_pct' => $revenue != 0.0 ? round($netProfit / $revenue * 100, 1) : null,
        ];
    }

    /**
     * Real, net-of-charges profit: revenue minus cost of goods, sales discounts (account 6130),
     * and delivery/return charges (accounts 6310/6320/6330/6340) recorded against each order's
     * posted invoice — the same figures the invoice detail "Record Charge/Discount" actions
     * write via laravel-accounting. Falls back to 0 for both when accounting isn't installed.
     *
     * @param  array<int, int>  $invoiceIds
     * @return array{discount: float, charges: float}
     */
    private function salesDeductions(array $invoiceIds): array
    {
        $expenseClass = 'Centrex\\Accounting\\Models\\Expense';
        $invoiceClass = 'Centrex\\Accounting\\Models\\Invoice';

        if ($invoiceIds === [] || !class_exists($expenseClass) || !class_exists($invoiceClass)) {
            return ['discount' => 0.0, 'charges' => 0.0];
        }

        $expenses = $expenseClass::query()
            ->where('chargeable_type', $invoiceClass)
            ->whereIn('chargeable_id', $invoiceIds)
            ->whereHas('account', function ($query): void {
                $query->whereIn('code', ['6130', '6310', '6320', '6330', '6340']);
            })
            ->with('account:id,code')
            ->get(['id', 'total', 'account_id']);

        return [
            'discount' => (float) $expenses->filter(static fn ($expense): bool => $expense->account?->code === '6130')->sum('total'),
            'charges'  => (float) $expenses->filter(static fn ($expense): bool => in_array($expense->account?->code, ['6310', '6320', '6330', '6340'], true))->sum('total'),
        ];
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Support;

use Centrex\Inventory\Enums\PriceTierCode;
use Centrex\Inventory\Models\SaleOrder;
use Illuminate\Support\Collection;

/**
 * Employee-wise and price-tier-wise revenue/order-count/profit breakdowns for a set of sale
 * orders — shared by the dashboard's Sales by Employee / Sales by Price Tier cards and the
 * Sales Report's Sale Statistics tab (InventorySalesStatisticsCard) so both group and format
 * the same way instead of each re-implementing the grouping logic.
 */
final class SalesBreakdowns
{
    /**
     * @param  Collection<int, SaleOrder>  $orders  must include id, sales_executive_id,
     *                                              created_by, price_tier_code, total_amount,
     *                                              cogs_amount, accounting_invoice_id
     * @return array<int, array{employee_id: ?int, name: string, orders_count: int, revenue: float, gross_profit: float, gross_margin_pct: ?float, by_price_tier: array<int, array{code: ?string, label: string, orders_count: int, revenue: float}>}>
     */
    public static function byEmployee(Collection $orders): array
    {
        $groups = $orders->groupBy(fn (SaleOrder $order): int|string => $order->sales_executive_id ?? $order->created_by ?? 'unassigned');

        $userModel = (string) config('auth.providers.users.model', 'App\\Models\\User');
        $employeeIds = $groups->keys()->filter(fn ($key): bool => $key !== 'unassigned')->values();
        $users = $employeeIds->isNotEmpty()
            ? $userModel::query()->whereIn('id', $employeeIds)->get(['id', 'name'])->keyBy('id')
            : collect();

        $summarizer = app(SalesOrderProfitSummary::class);

        return $groups->map(function (Collection $group, int|string $employeeId) use ($users, $summarizer): array {
            $summary = $summarizer->summarize($group);

            return [
                'employee_id'      => $employeeId === 'unassigned' ? null : (int) $employeeId,
                'name'             => $employeeId === 'unassigned' ? 'Unassigned' : ($users[$employeeId]->name ?? "User #{$employeeId}"),
                'orders_count'     => $summary['orders_count'],
                'revenue'          => $summary['revenue'],
                'gross_profit'     => $summary['gross_profit'],
                'gross_margin_pct' => $summary['gross_margin_pct'],
                'by_price_tier'    => self::tierCounts($group),
            ];
        })->sortByDesc('revenue')->values()->all();
    }

    /**
     * Order count + revenue per price tier within an already-scoped group of orders (e.g. one
     * employee's orders). Deliberately lighter than byPriceTier()/SalesOrderProfitSummary — no
     * COGS/discount/return queries — since this runs once per employee and only count+value are
     * needed here, not a full profit breakdown.
     *
     * @param  Collection<int, SaleOrder>  $orders  must include price_tier_code, total_amount
     * @return array<int, array{code: ?string, label: string, orders_count: int, revenue: float}>
     */
    private static function tierCounts(Collection $orders): array
    {
        return $orders->groupBy('price_tier_code')
            ->map(fn (Collection $group, ?string $code): array => [
                'code'         => $code,
                'label'        => PriceTierCode::labelFor($code) ?? ($code ?: 'Unassigned'),
                'orders_count' => $group->count(),
                'revenue'      => (float) $group->sum('total_amount'),
            ])
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, SaleOrder>  $orders  must include id, price_tier_code,
     *                                              total_amount, cogs_amount, accounting_invoice_id
     * @return array<int, array{code: ?string, label: string, orders_count: int, revenue: float, gross_profit: float, gross_margin_pct: ?float}>
     */
    public static function byPriceTier(Collection $orders): array
    {
        $summarizer = app(SalesOrderProfitSummary::class);

        return $orders->groupBy('price_tier_code')
            ->map(function (Collection $group, ?string $code) use ($summarizer): array {
                $summary = $summarizer->summarize($group);

                return [
                    'code'             => $code,
                    'label'            => PriceTierCode::labelFor($code) ?? ($code ?: 'Unassigned'),
                    'orders_count'     => $summary['orders_count'],
                    'revenue'          => $summary['revenue'],
                    'gross_profit'     => $summary['gross_profit'],
                    'gross_margin_pct' => $summary['gross_margin_pct'],
                ];
            })
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }
}

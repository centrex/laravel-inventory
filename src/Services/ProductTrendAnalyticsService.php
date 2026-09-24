<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Services;

use Centrex\Inventory\Models\{CustomerProductStat, ProductTrendSnapshot, SupplierProductStat};
use Illuminate\Support\Collection;

class ProductTrendAnalyticsService
{
    /**
     * Returns trend snapshots for a single product, ordered chronologically.
     *
     * @return array{product_id: int, period: string, snapshots: Collection, trend: array}
     */
    public function productTrends(
        int $productId,
        string $period = 'daily',
        int $days = 30,
        ?int $warehouseId = null,
    ): array {
        $from = now()->subDays($days)->startOfDay();

        $snapshots = ProductTrendSnapshot::query()
            ->where('product_id', $productId)
            ->where('period', $period)
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->where('snapshot_date', '>=', $from->toDateString())
            ->orderBy('snapshot_date')
            ->get();

        $revenues = $snapshots->pluck('revenue_amount')->map(fn ($v) => (float) $v);
        $margins = $snapshots->pluck('gross_margin_pct')->map(fn ($v) => (float) $v);
        $qtys = $snapshots->pluck('qty_sold')->map(fn ($v) => (float) $v);

        return [
            'product_id' => $productId,
            'period'     => $period,
            'days'       => $days,
            'snapshots'  => $snapshots,
            'trend'      => [
                'revenue_slope'      => $this->linearSlope($revenues->values()->all()),
                'qty_slope'          => $this->linearSlope($qtys->values()->all()),
                'avg_gross_margin'   => $margins->avg() !== null ? round((float) $margins->avg(), 2) : null,
                'peak_revenue_date'  => $snapshots->sortByDesc('revenue_amount')->first()?->snapshot_date?->toDateString(),
                'total_revenue'      => round((float) $revenues->sum(), 2),
                'total_qty'          => round((float) $qtys->sum(), 2),
                'total_cogs'         => round((float) $snapshots->sum('cogs_amount'), 2),
                'total_gross_profit' => round((float) $snapshots->sum('gross_profit_amount'), 2),
            ],
        ];
    }

    /**
     * Per-product purchase statistics for a customer — useful for churn detection and reorder forecasting.
     *
     * @return array{customer_id: int, stats: Collection, top_products: Collection}
     */
    public function customerProductStats(int $customerId): array
    {
        $stats = CustomerProductStat::query()
            ->with(['product', 'variant'])
            ->where('customer_id', $customerId)
            ->orderByDesc('total_revenue_amount')
            ->get();

        $top = $stats->take(10)->map(fn (CustomerProductStat $s): array => [
            'product_id'              => $s->product_id,
            'product_name'            => $s->product?->name ?? ('#' . $s->product_id),
            'sku'                     => $s->product?->sku,
            'variant_id'              => $s->variant_id,
            'total_orders'            => $s->total_orders,
            'total_qty_ordered'       => $s->total_qty_ordered,
            'total_revenue_amount'    => $s->total_revenue_amount,
            'avg_unit_price_amount'   => $s->avg_unit_price_amount,
            'avg_order_interval_days' => $s->avg_order_interval_days,
            'return_rate_pct'         => $s->return_rate_pct,
            'first_ordered_at'        => $s->first_ordered_at?->toDateString(),
            'last_ordered_at'         => $s->last_ordered_at?->toDateString(),
            'days_since_last_order'   => $s->last_ordered_at ? $s->last_ordered_at->diffInDays(now()) : null,
            'reorder_due'             => $s->avg_order_interval_days !== null && $s->last_ordered_at
                ? $s->last_ordered_at->addDays((int) ceil((float) $s->avg_order_interval_days))->toDateString()
                : null,
        ]);

        return [
            'customer_id'  => $customerId,
            'stats'        => $stats,
            'top_products' => $top,
        ];
    }

    /**
     * Per-product supply statistics for a supplier — cost trend, lead time, reliability.
     *
     * @return array{supplier_id: int, stats: Collection, top_products: Collection}
     */
    public function supplierProductStats(int $supplierId): array
    {
        $stats = SupplierProductStat::query()
            ->with(['product', 'variant'])
            ->where('supplier_id', $supplierId)
            ->orderByDesc('total_cost_amount')
            ->get();

        $top = $stats->take(10)->map(fn (SupplierProductStat $s): array => [
            'product_id'           => $s->product_id,
            'product_name'         => $s->product?->name ?? ('#' . $s->product_id),
            'sku'                  => $s->product?->sku,
            'variant_id'           => $s->variant_id,
            'total_orders'         => $s->total_orders,
            'total_qty_ordered'    => $s->total_qty_ordered,
            'total_qty_received'   => $s->total_qty_received,
            'total_cost_amount'    => $s->total_cost_amount,
            'avg_unit_cost_amount' => $s->avg_unit_cost_amount,
            'min_unit_cost_amount' => $s->min_unit_cost_amount,
            'max_unit_cost_amount' => $s->max_unit_cost_amount,
            'cost_spread_pct'      => $s->min_unit_cost_amount > 0
                ? round(($s->max_unit_cost_amount - $s->min_unit_cost_amount) / $s->min_unit_cost_amount * 100, 2)
                : 0.0,
            'avg_lead_time_days'       => $s->avg_lead_time_days,
            'on_time_receipt_rate_pct' => $s->on_time_receipt_rate_pct,
            'fulfillment_rate_pct'     => $s->fulfillment_rate_pct,
            'first_ordered_at'         => $s->first_ordered_at?->toDateString(),
            'last_ordered_at'          => $s->last_ordered_at?->toDateString(),
        ]);

        return [
            'supplier_id'  => $supplierId,
            'stats'        => $stats,
            'top_products' => $top,
        ];
    }

    /**
     * Product profitability report over a date range.
     *
     * Groups trend snapshots by product (or category/brand via $groupBy)
     * and ranks by gross profit, margin, and revenue to surface the most
     * and least profitable items.
     *
     * @param  string  $groupBy  'product' | 'category' | 'brand'
     * @return array{from: string, to: string, group_by: string, rows: Collection, summary: array}
     */
    public function profitabilityReport(
        string $from,
        string $to,
        string $groupBy = 'product',
        ?int $warehouseId = null,
    ): array {
        $rows = ProductTrendSnapshot::query()
            ->with('product.category', 'product.brand')
            ->where('period', 'daily')
            ->whereBetween('snapshot_date', [$from, $to])
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->get()
            ->groupBy(function (ProductTrendSnapshot $snap) use ($groupBy): int|string {
                return match ($groupBy) {
                    'category' => $snap->product?->category_id ?? 'Uncategorized',
                    'brand'    => $snap->product?->brand_id ?? 'No Brand',
                    default    => $snap->product_id,
                };
            })
            ->map(function (Collection $snaps, int|string $key) use ($groupBy): array {
                $first = $snaps->first();
                $revenue = (float) $snaps->sum('revenue_amount');
                $cogs = (float) $snaps->sum('cogs_amount');
                $grossProfit = (float) $snaps->sum('gross_profit_amount');
                $qtySold = (float) $snaps->sum('qty_sold');
                $grossMargin = $revenue > 0 ? round($grossProfit / $revenue * 100, 2) : 0.0;

                $label = match ($groupBy) {
                    'category' => $first?->product?->category?->name ?? ('Category #' . $key),
                    'brand'    => $first?->product?->brand?->name ?? ('Brand #' . $key),
                    default    => $first?->product?->name ?? ('Product #' . $key),
                };

                return [
                    'key'              => $key,
                    'label'            => $label,
                    'sku'              => $groupBy === 'product' ? ($first?->product?->sku) : null,
                    'qty_sold'         => round($qtySold, 2),
                    'revenue_amount'   => round($revenue, 2),
                    'cogs_amount'      => round($cogs, 2),
                    'gross_profit'     => round($grossProfit, 2),
                    'gross_margin_pct' => $grossMargin,
                    'orders_count'     => (int) $snaps->sum('orders_count'),
                    'customers_count'  => $snaps->max('customers_count'),
                ];
            })
            ->sortByDesc('gross_profit')
            ->values();

        $totalRevenue = (float) $rows->sum('revenue_amount');
        $totalCogs = (float) $rows->sum('cogs_amount');
        $totalProfit = (float) $rows->sum('gross_profit');

        return [
            'from'     => $from,
            'to'       => $to,
            'group_by' => $groupBy,
            'rows'     => $rows,
            'summary'  => [
                'total_revenue'      => round($totalRevenue, 2),
                'total_cogs'         => round($totalCogs, 2),
                'total_gross_profit' => round($totalProfit, 2),
                'avg_gross_margin'   => $totalRevenue > 0 ? round($totalProfit / $totalRevenue * 100, 2) : 0.0,
                'top_product'        => $rows->first()['label'] ?? null,
                'loss_makers'        => $rows->filter(fn ($r) => (float) $r['gross_profit'] < 0)->count(),
            ],
        ];
    }

    /**
     * Least-squares linear slope for a sequence of values.
     * Positive = growing, negative = declining.
     *
     * @param  float[]  $values
     */
    private function linearSlope(array $values): ?float
    {
        $n = count($values);

        if ($n < 2) {
            return null;
        }

        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumX2 = 0.0;

        foreach ($values as $i => $y) {
            $sumX += $i;
            $sumY += $y;
            $sumXY += $i * $y;
            $sumX2 += $i * $i;
        }

        $denom = $n * $sumX2 - $sumX * $sumX;

        if ($denom == 0) {
            return 0.0;
        }

        return round(($n * $sumXY - $sumX * $sumY) / $denom, 6);
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory;

use Centrex\Inventory\Models\{Adjustment, Customer, CustomerProductStat, Product, ProductTrendSnapshot, Supplier, SupplierProductStat, Transfer, Warehouse};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Central service class for all inventory operations.
 *
 * This is the class behind the {@see Facades\Inventory} facade.
 * It is organised into logical sections — each separated by a comment banner:
 *
 *   Exchange Rates      – set / get / convert currency rates
 *   Price Management    – set, resolve, and list product prices per tier
 *   Stock Ledger        – read on-hand, reserved, and in-transit quantities
 *   WAC Engine          – internal weighted-average-cost recalculation helpers
 *   Purchase Orders     – create, submit, confirm, receive, cancel
 *   Stock Receipts      – create / post / void goods-received notes (GRN)
 *   Sale Orders         – create, confirm, reserve, fulfil, cancel, quotation convert
 *   Returns             – customer returns (SO) and supplier returns (PO)
 *   Transfers           – inter-warehouse stock moves
 *   Adjustments         – cycle-count / write-off stock corrections
 *   Coupons             – discount code resolution
 *   Entity Management   – warehouses, products, customers, suppliers, partners
 *   Reports & Analytics – stock valuation, movement history, sales forecast
 *   Financing           – inventory financing and loan facility tracking
 *   Internal Helpers    – document numbering, metadata, WAC writes, etc.
 */
class Inventory
{
    use Concerns\GeneratesInventoryReports;
    use Concerns\GeneratesSalesForecast;
    use Concerns\HasInventoryHelpers;
    use Concerns\HasSharedInventoryHelpers;
    use Concerns\ManagesAdjustments;
    use Concerns\ManagesExchangeRates;
    use Concerns\ManagesInterWarehouseShipments;
    use Concerns\ManagesPickPackShip;
    use Concerns\ManagesPricing;
    use Concerns\ManagesPurchaseOrders;
    use Concerns\ManagesReturns;
    use Concerns\ManagesSaleOrderLifecycle;
    use Concerns\ManagesSaleOrders;
    use Concerns\ManagesStockLedger;
    use Concerns\ManagesStockReceipts;
    use Concerns\ManagesTransfers;
    use Concerns\QueriesInventoryEntities;

    /**
     * Memoised `inventory.qty_tolerance`, keyed by application instance.
     *
     * The tolerance is consulted on nearly every quantity comparison, including inside the
     * per-line loops of the receipt, fulfilment, transfer and adjustment paths — so it was
     * being re-read from config dozens of times per posted document, each read a container
     * resolve plus a dotted-key lookup. Keying by container identity keeps a rebuilt
     * application (Testbench, Octane) from inheriting the previous one's value.
     *
     * @var array<int, float>
     */
    private static array $qtyTolerances = [];

    /** Quantity comparison tolerance, used to absorb float rounding on decimal quantities. */
    private function qtyTolerance(): float
    {
        return self::$qtyTolerances[spl_object_id(app())] ??= (float) config('inventory.qty_tolerance', 0.0001);
    }

    // -------------------------------------------------------------------------
    // Partner Management
    // -------------------------------------------------------------------------

    /**
     * Create a new API partner (dropshipper / e-commerce / B2B / marketplace).
     * Returns the partner with the generated api_key (shown only once).
     */
    public function createPartner(array $data): Partner
    {
        return Partner::create([
            'name'                  => $data['name'],
            'type'                  => $data['type'] ?? 'dropshipper',
            'api_key'               => Partner::generateApiKey(),
            'customer_id'           => $data['customer_id'] ?? null,
            'default_warehouse_id'  => $data['default_warehouse_id'] ?? null,
            'default_price_tier'    => $data['default_price_tier'] ?? 'B2B_WHOLESALE',
            'can_view_stock'        => $data['can_view_stock'] ?? true,
            'can_view_prices'       => $data['can_view_prices'] ?? true,
            'can_create_orders'     => $data['can_create_orders'] ?? true,
            'is_active'             => $data['is_active'] ?? true,
            'allowed_warehouse_ids' => $data['allowed_warehouse_ids'] ?? null,
            'allowed_product_ids'   => $data['allowed_product_ids'] ?? null,
        ]);
    }

    public function updatePartner(int $partnerId, array $data): Partner
    {
        $partner = Partner::findOrFail($partnerId);
        $partner->update($data);

        return $partner->refresh();
    }

    /**
     * Rotate the API key for a partner. Only the hash is persisted — the returned model's
     * getPlainApiKey() holds the new plaintext key for exactly this one response.
     */
    public function rotatePartnerApiKey(int $partnerId): Partner
    {
        $partner = Partner::findOrFail($partnerId);
        $partner->update(['api_key' => Partner::generateApiKey()]);

        return $partner;
    }

    public function listPartners(bool $activeOnly = true): Collection
    {
        return Partner::when($activeOnly, fn ($q) => $q->where('is_active', true))->get();
    }

    // -------------------------------------------------------------------------
    // Product Trend & Profitability Analytics
    // -------------------------------------------------------------------------

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

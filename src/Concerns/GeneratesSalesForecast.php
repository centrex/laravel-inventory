<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Concerns;

use Centrex\Accounting\Models\{Bill, Invoice};
use Centrex\Inventory\Enums\{PurchaseOrderStatus, SaleOrderStatus};
use Centrex\Inventory\Models\{Customer, PurchaseOrder, PurchaseOrderItem, SaleOrder, SaleOrderItem, WarehouseProduct};
use Centrex\Inventory\Support\{CommercialTeamAccess, DayRange, SalesTargetCalculator};
use DateTimeInterface;
use Illuminate\Support\{Carbon, Collection};

/**
 * Inventory::salesForecast() is the heaviest computation in this class — a rolling
 * demand/cash-flow projection built from trailing sale-order history, blended with a
 * BG/NBD + Gamma-Gamma customer lifetime-value model. salesTarget()/customerAnalytics()/
 * customerSalesHeatmap() are lighter analytics built on the same historical query shape.
 */
trait GeneratesSalesForecast
{
    public function salesForecast(
        int $lookbackDays = 90,
        int $forecastDays = 90,
        int $productLimit = 12,
        int $customerLimit = 10,
    ): array {
        $lookbackDays = max(7, $lookbackDays);
        $forecastDays = max(7, $forecastDays);

        $historyEnd = now()->endOfDay();
        $historyStart = now()->copy()->subDays($lookbackDays - 1)->startOfDay();
        $observedDays = max(1, (int) floor($historyStart->diffInDays($historyEnd)) + 1);

        $saleOrdersQuery = SaleOrder::query()
            ->with(['customer', 'items.product'])
            ->where('document_type', 'order')
            ->whereIn('status', [
                SaleOrderStatus::CONFIRMED->value,
                SaleOrderStatus::PROCESSING->value,
                SaleOrderStatus::PARTIAL->value,
                SaleOrderStatus::FULFILLED->value,
                SaleOrderStatus::COMPLETED->value,
            ])
            ->whereBetween('ordered_at', [$historyStart, $historyEnd]);

        CommercialTeamAccess::applySalesScope($saleOrdersQuery);

        $saleOrders = $saleOrdersQuery->get();

        $items = $saleOrders->flatMap(function (SaleOrder $order): Collection {
            return $order->items->map(fn (SaleOrderItem $item): array => [
                'product_id'    => (int) $item->product_id,
                'product_name'  => $item->product?->name ?? ('#' . $item->product_id),
                'sku'           => $item->product?->sku ?? null,
                'customer_id'   => $order->customer_id ? (int) $order->customer_id : null,
                'customer_name' => $order->customer?->name ?? 'Walk-in',
                'zone'          => $order->customer?->zone ?: 'Unassigned',
                'area'          => $order->customer?->area ?: 'Unassigned',
                'demographic'   => $order->customer?->demographic_segment ?: 'Unassigned',
                'qty'           => (float) $item->qty_ordered,
                'fulfilled_qty' => (float) $item->qty_fulfilled,
                'revenue'       => (float) $item->line_total_local,
                'ordered_at'    => $order->ordered_at,
            ]);
        });

        $productIds = $items->pluck('product_id')
            ->filter()
            ->unique()
            ->values();

        $stockByProduct = WarehouseProduct::query()
            ->when($productIds->isNotEmpty(), fn ($query) => $query->whereIn('product_id', $productIds->all()))
            ->get()
            ->groupBy('product_id')
            ->map(function (Collection $rows): array {
                $qtyOnHand = (float) $rows->sum('qty_on_hand');
                $qtyReserved = (float) $rows->sum('qty_reserved');
                $qtyInTransit = (float) $rows->sum('qty_in_transit');
                $wacValue = (float) $rows->sum(fn (WarehouseProduct $row): float => (float) $row->qty_on_hand * (float) $row->wac_amount);

                return [
                    'qty_on_hand'    => round($qtyOnHand, 2),
                    'qty_reserved'   => round($qtyReserved, 2),
                    'qty_in_transit' => round($qtyInTransit, 2),
                    'qty_available'  => round($qtyOnHand - $qtyReserved, 2),
                    'wac_amount'     => $qtyOnHand > 0 ? round($wacValue / $qtyOnHand, 4) : 0.0,
                ];
            });

        $pendingSupplyByProduct = PurchaseOrderItem::query()
            ->with('purchaseOrder')
            ->when($productIds->isNotEmpty(), fn ($query) => $query->whereIn('product_id', $productIds->all()))
            ->get()
            ->filter(function (PurchaseOrderItem $item): bool {
                $status = $item->purchaseOrder?->status?->value;

                return in_array($status, [
                    PurchaseOrderStatus::DRAFT->value,
                    PurchaseOrderStatus::SUBMITTED->value,
                    PurchaseOrderStatus::CONFIRMED->value,
                    PurchaseOrderStatus::PARTIAL->value,
                ], true);
            })
            ->groupBy('product_id')
            ->map(function (Collection $rows): array {
                $pendingQty = (float) $rows->sum(fn (PurchaseOrderItem $row): float => $row->qtyPending());
                $pendingValue = (float) $rows->sum(fn (PurchaseOrderItem $row): float => $row->qtyPending() * (float) $row->unit_price_local);

                return [
                    'qty'      => round($pendingQty, 2),
                    'avg_cost' => $pendingQty > 0 ? round($pendingValue / $pendingQty, 4) : 0.0,
                ];
            });

        $customerCollectionRatio = $this->historicalCustomerCollectionRatio($historyStart, $historyEnd);
        $supplierPaymentRatio = $this->historicalSupplierPaymentRatio($historyStart, $historyEnd);

        $productForecast = $items
            ->groupBy('product_id')
            ->map(function (Collection $rows, int|string $productId) use ($observedDays, $forecastDays, $stockByProduct, $pendingSupplyByProduct): array {
                $productId = (int) $productId;
                $historyQty = (float) $rows->sum('qty');
                $fulfilledQty = (float) $rows->sum('fulfilled_qty');
                $historyRevenue = (float) $rows->sum('revenue');
                $activeDays = $rows->pluck('ordered_at')
                    ->filter()
                    ->map(fn ($orderedAt) => $orderedAt->toDateString())
                    ->unique()
                    ->count();

                $avgDailyQty = $historyQty > 0 ? $historyQty / $observedDays : 0.0;
                $avgDailyRevenue = $historyRevenue > 0 ? $historyRevenue / $observedDays : 0.0;
                $forecastQty = round($avgDailyQty * $forecastDays, 2);
                $forecastRevenue = round($avgDailyRevenue * $forecastDays, 2);
                $avgSellPrice = $historyQty > 0 ? round($historyRevenue / $historyQty, 4) : 0.0;

                $stock = $stockByProduct->get($productId, [
                    'qty_on_hand'    => 0.0,
                    'qty_reserved'   => 0.0,
                    'qty_in_transit' => 0.0,
                    'qty_available'  => 0.0,
                    'wac_amount'     => 0.0,
                ]);
                $pendingSupply = $pendingSupplyByProduct->get($productId, [
                    'qty'      => 0.0,
                    'avg_cost' => 0.0,
                ]);

                $availableSoon = (float) $stock['qty_available'] + (float) $stock['qty_in_transit'] + (float) $pendingSupply['qty'];
                $forecastGapQty = max(0.0, $forecastQty - $availableSoon);
                $daysOfCover = $avgDailyQty > 0 ? round(max(0.0, (float) $stock['qty_available']) / $avgDailyQty, 1) : null;
                $stockoutDate = $avgDailyQty > 0 && (float) $stock['qty_available'] > 0
                    ? now()->copy()->addDays((int) ceil((float) $stock['qty_available'] / $avgDailyQty))->toDateString()
                    : null;
                $procurementUnitCost = (float) ($pendingSupply['avg_cost'] > 0 ? $pendingSupply['avg_cost'] : $stock['wac_amount']);
                $forecastProcurementCost = round($forecastGapQty * $procurementUnitCost, 2);

                return [
                    'product_id'                => $productId,
                    'product_name'              => (string) $rows->first()['product_name'],
                    'sku'                       => $rows->first()['sku'],
                    'history_qty'               => round($historyQty, 2),
                    'fulfilled_qty'             => round($fulfilledQty, 2),
                    'history_revenue'           => round($historyRevenue, 2),
                    'avg_daily_qty'             => round($avgDailyQty, 4),
                    'avg_daily_revenue'         => round($avgDailyRevenue, 2),
                    'forecast_qty'              => $forecastQty,
                    'forecast_revenue'          => $forecastRevenue,
                    'avg_sell_price'            => $avgSellPrice,
                    'qty_available'             => round((float) $stock['qty_available'], 2),
                    'qty_in_transit'            => round((float) $stock['qty_in_transit'], 2),
                    'pending_supply_qty'        => round((float) $pendingSupply['qty'], 2),
                    'available_soon_qty'        => round($availableSoon, 2),
                    'forecast_gap_qty'          => round($forecastGapQty, 2),
                    'days_of_cover'             => $daysOfCover,
                    'stockout_date'             => $stockoutDate,
                    'active_days'               => $activeDays,
                    'confidence'                => round(min(100, ($activeDays / $observedDays) * 100), 1),
                    'forecast_procurement_cost' => $forecastProcurementCost,
                ];
            })
            ->sortByDesc('forecast_revenue')
            ->values();

        $customerForecast = $saleOrders
            ->groupBy(fn (SaleOrder $order) => $order->customer_id ?: 'walk-in')
            ->map(function (Collection $orders, int|string $customerKey) use ($observedDays, $forecastDays): array {
                $customerId = is_numeric($customerKey) ? (int) $customerKey : null;
                $ordersCount = $orders->count();
                $qty = (float) $orders->sum(fn (SaleOrder $order): float => (float) $order->items->sum('qty_ordered'));
                $revenue = (float) $orders->sum('total_local');
                $products = $orders->flatMap(fn (SaleOrder $order) => $order->items->pluck('product_id'))
                    ->filter()
                    ->unique()
                    ->count();
                $avgDailyQty = $qty > 0 ? $qty / $observedDays : 0.0;
                $avgDailyRevenue = $revenue > 0 ? $revenue / $observedDays : 0.0;

                return [
                    'customer_id'       => $customerId,
                    'customer_name'     => $customerId ? ($orders->first()?->customer?->name ?? 'Customer #' . $customerId) : 'Walk-in',
                    'zone'              => $orders->first()?->customer?->zone ?: 'Unassigned',
                    'area'              => $orders->first()?->customer?->area ?: 'Unassigned',
                    'demographic'       => $orders->first()?->customer?->demographic_segment ?: 'Unassigned',
                    'segment'           => $this->customerSegment($revenue, $ordersCount),
                    'orders_count'      => $ordersCount,
                    'products_count'    => $products,
                    'history_qty'       => round($qty, 2),
                    'history_revenue'   => round($revenue, 2),
                    'avg_daily_qty'     => round($avgDailyQty, 4),
                    'avg_daily_revenue' => round($avgDailyRevenue, 2),
                    'forecast_qty'      => round($avgDailyQty * $forecastDays, 2),
                    'forecast_revenue'  => round($avgDailyRevenue * $forecastDays, 2),
                ];
            })
            ->sortByDesc('forecast_revenue')
            ->values();

        $zoneForecast = $this->geographicCustomerForecast($saleOrders, $observedDays, $forecastDays, 'zone');
        $areaForecast = $this->geographicCustomerForecast($saleOrders, $observedDays, $forecastDays, 'area');
        $demographicForecast = $this->geographicCustomerForecast($saleOrders, $observedDays, $forecastDays, 'demographic_segment');

        $timeline = $this->buildForecastTimeline(
            $productForecast,
            $forecastDays,
            $customerCollectionRatio,
            $supplierPaymentRatio,
        );

        $holisticRequirement = [
            'products_tracked' => $productForecast->count(),
            'products_at_risk' => $productForecast
                ->filter(fn (array $product): bool => (float) $product['forecast_gap_qty'] > 0)
                ->count(),
            'forecast_qty'              => round((float) $productForecast->sum('forecast_qty'), 2),
            'forecast_revenue'          => round((float) $productForecast->sum('forecast_revenue'), 2),
            'required_qty'              => round((float) $productForecast->sum('forecast_gap_qty'), 2),
            'required_procurement_cost' => round((float) $productForecast->sum('forecast_procurement_cost'), 2),
            'collection_ratio'          => round($customerCollectionRatio, 2),
            'supplier_payment_ratio'    => round($supplierPaymentRatio, 2),
            'forecast_cash_in'          => round((float) $timeline['totals']['cash_in'], 2),
            'forecast_cash_out'         => round((float) $timeline['totals']['cash_out'], 2),
            'forecast_cash_net'         => round((float) $timeline['totals']['cash_net'], 2),
        ];

        return [
            'window' => [
                'history_start' => $historyStart->toDateString(),
                'history_end'   => $historyEnd->toDateString(),
                'lookback_days' => $lookbackDays,
                'forecast_days' => $forecastDays,
            ],
            'summary'      => $holisticRequirement,
            'products'     => $productForecast->take($productLimit)->values(),
            'customers'    => $customerForecast->take($customerLimit)->values(),
            'zones'        => $zoneForecast,
            'areas'        => $areaForecast,
            'demographics' => $demographicForecast,
            'timeline'     => $timeline,
        ];
    }

    public function salesTarget(
        int $lookbackDays = 90,
        int $targetDays = 30,
        ?float $expectedGrossMarginPct = null,
        float $desiredNetMarginPct = 10.0,
        float $growthPct = 0.0,
        ?float $expenseAllocationPct = null,
    ): array {
        return app(SalesTargetCalculator::class)->calculate(
            lookbackDays: $lookbackDays,
            targetDays: $targetDays,
            expectedGrossMarginPct: $expectedGrossMarginPct,
            desiredNetMarginPct: $desiredNetMarginPct,
            growthPct: $growthPct,
            expenseAllocationPct: $expenseAllocationPct,
        );
    }

    public function customerAnalytics(int $customerId, int $lookbackDays = 180, int $forecastDays = 90): array
    {
        $lookbackDays = max(7, $lookbackDays);
        $forecastDays = max(7, $forecastDays);
        $historyEnd = now()->endOfDay();
        $historyStart = now()->copy()->subDays($lookbackDays - 1)->startOfDay();
        $observedDays = max(1, (int) floor($historyStart->diffInDays($historyEnd)) + 1);

        // Lookback window — full details for trend + product analysis
        $ordersQuery = SaleOrder::query()
            ->with(['items.product', 'customer'])
            ->where('document_type', 'order')
            ->where('customer_id', $customerId)
            ->whereBetween('ordered_at', [$historyStart, $historyEnd])
            ->whereNotIn('status', [SaleOrderStatus::CANCELLED->value, SaleOrderStatus::RETURNED->value]);

        CommercialTeamAccess::applySalesScope($ordersQuery);

        $orders = $ordersQuery->get();
        $customer = $orders->first()?->customer ?? Customer::query()->find($customerId);
        $qty = (float) $orders->sum(fn (SaleOrder $o): float => (float) $o->items->sum('qty_ordered'));
        $revenue = (float) $orders->sum('total_local');

        $avgDailyRevenue = $revenue > 0 ? $revenue / $observedDays : 0.0;
        $avgDailyQty = $qty > 0 ? $qty / $observedDays : 0.0;
        $lastOrder = $orders->sortByDesc(fn (SaleOrder $o) => $o->ordered_at?->getTimestamp() ?? 0)->first();
        $daysSince = $lastOrder?->ordered_at ? (int) $lastOrder->ordered_at->diffInDays(now()) : null;
        $avgOrderValue = $orders->count() > 0 ? round($revenue / $orders->count(), 2) : 0.0;

        // All-time — lightweight query (order-level only) for CLV and RFM
        $allTimeQuery = SaleOrder::query()
            ->where('document_type', 'order')
            ->where('customer_id', $customerId)
            ->whereNotIn('status', [SaleOrderStatus::CANCELLED->value, SaleOrderStatus::RETURNED->value])
            ->orderBy('ordered_at');

        CommercialTeamAccess::applySalesScope($allTimeQuery);

        $allTimeOrders = $allTimeQuery->get(['id', 'ordered_at', 'total_local']);
        $allTimeCount = $allTimeOrders->count();
        $allTimeRevenue = (float) $allTimeOrders->sum('total_local');
        $firstOrderAt = $allTimeOrders->first()?->ordered_at;
        $customerAgeDays = $firstOrderAt ? max(1, (int) $firstOrderAt->diffInDays(now())) : 1;

        // Purchase frequency
        $ordersPerMonth = ($orders->count() / max(1, $observedDays)) * 30;
        $ordersPerYear = $ordersPerMonth * 12;

        // Average purchase interval (all-time)
        $avgPurchaseInterval = $allTimeCount > 1
            ? (int) round($customerAgeDays / ($allTimeCount - 1))
            : null;

        // CLV — avg order value × annual frequency × projected lifespan
        $segment = $this->customerSegment($revenue, $orders->count());
        $lifespanYears = match ($segment) {
            'Strategic' => 5.0,
            'Growth'    => 3.0,
            'Repeat'    => 2.0,
            default     => 1.0,
        };
        $clvSimple = round($avgOrderValue * $ordersPerYear * $lifespanYears, 2);

        // Churn risk
        $churnRisk = 'none';

        if ($daysSince !== null) {
            if ($avgPurchaseInterval !== null && $avgPurchaseInterval > 0) {
                $ratio = $daysSince / $avgPurchaseInterval;
                $churnRisk = match (true) {
                    $ratio >= 3.0 => 'high',
                    $ratio >= 2.0 => 'medium',
                    $ratio >= 1.5 => 'low',
                    default       => 'none',
                };
            } else {
                $churnRisk = match (true) {
                    $daysSince > 180 => 'high',
                    $daysSince > 90  => 'medium',
                    $daysSince > 45  => 'low',
                    default          => 'none',
                };
            }
        }

        // RFM scores (1–5)
        $rfmRecency = match (true) {
            $daysSince === null => 1,
            $daysSince <= 7     => 5,
            $daysSince <= 30    => 4,
            $daysSince <= 90    => 3,
            $daysSince <= 180   => 2,
            default             => 1,
        };
        $rfmFrequency = match (true) {
            $allTimeCount >= 24 => 5,
            $allTimeCount >= 12 => 4,
            $allTimeCount >= 6  => 3,
            $allTimeCount >= 2  => 2,
            default             => 1,
        };
        $rfmMonetary = match (true) {
            $allTimeRevenue >= 500000 => 5,
            $allTimeRevenue >= 100000 => 4,
            $allTimeRevenue >= 20000  => 3,
            $allTimeRevenue >= 5000   => 2,
            default                   => 1,
        };
        $rfmAvg = ($rfmRecency + $rfmFrequency + $rfmMonetary) / 3.0;
        $rfmLabel = match (true) {
            $rfmRecency >= 4 && $rfmFrequency >= 4 && $rfmMonetary >= 4 => 'VIP',
            $rfmAvg >= 4.0                                              => 'Loyal',
            $rfmRecency <= 2 && $rfmFrequency >= 3 && $rfmMonetary >= 4 => 'Cannot Lose',
            $rfmRecency <= 2 && $rfmAvg >= 3.0                          => 'At Risk',
            $rfmRecency <= 2                                            => 'Lost',
            $rfmRecency >= 4 && $rfmFrequency <= 2                      => 'Promising',
            $rfmFrequency >= 3                                          => 'Potential Loyal',
            default                                                     => 'Active',
        };

        // Monthly trend (lookback window, ascending)
        $monthlyTrend = $orders
            ->groupBy(fn (SaleOrder $o): string => $o->ordered_at?->format('Y-m') ?? 'unknown')
            ->map(fn (Collection $monthOrders, string $key): array => [
                'month'        => Carbon::createFromFormat('Y-m', $key)?->format('M y') ?? $key,
                'revenue'      => round((float) $monthOrders->sum('total_local'), 2),
                'orders_count' => $monthOrders->count(),
                'qty'          => round((float) $monthOrders->sum(fn (SaleOrder $o): float => (float) $o->items->sum('qty_ordered')), 2),
            ])
            ->sortKeys()
            ->values()
            ->all();

        // Top 5 products by revenue (lookback window)
        $topProducts = $orders
            ->flatMap(fn (SaleOrder $o) => $o->items)
            ->groupBy('product_id')
            ->map(fn (Collection $items, mixed $productId): array => [
                'product_id'   => $productId,
                'name'         => $items->first()?->product?->name ?? 'Unknown',
                'qty'          => round((float) $items->sum('qty_ordered'), 2),
                'revenue'      => round((float) $items->sum('line_total_local'), 2),
                'orders_count' => $items->pluck('sale_order_id')->unique()->count(),
            ])
            ->sortByDesc('revenue')
            ->values()
            ->take(5)
            ->all();

        return [
            // Profile
            'segment'          => $segment,
            'zone'             => $customer?->zone ?: 'Unassigned',
            'area'             => $customer?->area ?: 'Unassigned',
            'demographic'      => $customer?->demographic_segment ?: 'Unassigned',
            'demographic_data' => $customer?->demographic_data ?: [],

            // Lookback window
            'orders_count'      => $orders->count(),
            'history_qty'       => round($qty, 2),
            'history_revenue'   => round($revenue, 2),
            'avg_order_value'   => $avgOrderValue,
            'forecast_qty'      => round($avgDailyQty * $forecastDays, 2),
            'forecast_revenue'  => round($avgDailyRevenue * $forecastDays, 2),
            'last_order_at'     => $lastOrder?->ordered_at?->toDateString(),
            'days_since_order'  => $daysSince,
            'distinct_products' => $orders->flatMap(fn (SaleOrder $o) => $o->items->pluck('product_id'))->filter()->unique()->count(),
            'orders_per_month'  => round($ordersPerMonth, 2),
            'forecast_days'     => $forecastDays,
            'lookback_days'     => $lookbackDays,

            // All-time
            'first_order_at'    => $firstOrderAt?->toDateString(),
            'customer_age_days' => $customerAgeDays,
            'all_time_orders'   => $allTimeCount,
            'all_time_revenue'  => round($allTimeRevenue, 2),

            // CLV
            'clv_simple'            => $clvSimple,
            'clv_lifespan_years'    => $lifespanYears,
            'purchase_frequency'    => round($ordersPerYear, 1),
            'avg_purchase_interval' => $avgPurchaseInterval,

            // RFM
            'rfm_recency'   => $rfmRecency,
            'rfm_frequency' => $rfmFrequency,
            'rfm_monetary'  => $rfmMonetary,
            'rfm_label'     => $rfmLabel,

            // Churn
            'churn_risk' => $churnRisk,

            // Trend + Products
            'monthly_trend' => $monthlyTrend,
            'top_products'  => $topProducts,
        ];
    }

    public function customerSalesHeatmap(
        string $startDate = '',
        string $endDate = '',
        string $metric = 'revenue',
    ): array {
        $sortFn = static fn (string $a, string $b): int => $a === 'Unassigned' ? 1 : ($b === 'Unassigned' ? -1 : strcmp($a, $b));

        $query = SaleOrder::query()
            ->with('customer:id,geo')
            ->where('document_type', 'order')
            ->whereNotIn('status', [SaleOrderStatus::CANCELLED->value, SaleOrderStatus::RETURNED->value]);

        if ($startDate !== '') {
            $query->where('ordered_at', '>=', DayRange::from($startDate));
        }

        if ($endDate !== '') {
            $query->where('ordered_at', '<', DayRange::until($endDate));
        }

        CommercialTeamAccess::applySalesScope($query);

        $orders = $query->get(['id', 'customer_id', 'total_local', 'status', 'ordered_at']);

        // Accumulate into zone × area buckets
        $raw = [];

        foreach ($orders as $order) {
            $zone = trim((string) ($order->customer?->zone ?: '')) ?: 'Unassigned';
            $area = trim((string) ($order->customer?->area ?: '')) ?: 'Unassigned';

            if (!isset($raw[$zone][$area])) {
                $raw[$zone][$area] = ['revenue' => 0.0, 'orders' => 0, 'customer_ids' => []];
            }

            $raw[$zone][$area]['revenue'] += (float) $order->total_local;
            $raw[$zone][$area]['orders']++;

            if ($order->customer_id !== null) {
                $raw[$zone][$area]['customer_ids'][(int) $order->customer_id] = true;
            }
        }

        // Collect and sort zones and areas — 'Unassigned' always last
        $zones = array_keys($raw);
        usort($zones, $sortFn);

        $allAreas = [];

        foreach ($raw as $areaMap) {
            foreach (array_keys($areaMap) as $a) {
                $allAreas[$a] = true;
            }
        }
        $areas = array_keys($allAreas);
        usort($areas, $sortFn);

        // Build normalised cell matrix
        $cells = [];

        foreach ($zones as $zone) {
            foreach ($areas as $area) {
                $bucket = $raw[$zone][$area] ?? null;
                $revenue = $bucket ? round($bucket['revenue'], 2) : 0.0;
                $oCount = $bucket ? $bucket['orders'] : 0;
                $cCount = $bucket ? count($bucket['customer_ids']) : 0;
                $avgOrder = $oCount > 0 ? round($revenue / $oCount, 2) : 0.0;

                $cells[$zone][$area] = [
                    'revenue'   => $revenue,
                    'orders'    => $oCount,
                    'customers' => $cCount,
                    'avg_order' => $avgOrder,
                ];
            }
        }

        // Max cell value for the chosen metric (used for color intensity)
        $maxValue = 0.0;

        foreach ($zones as $zone) {
            foreach ($areas as $area) {
                $val = match ($metric) {
                    'orders'    => (float) $cells[$zone][$area]['orders'],
                    'customers' => (float) $cells[$zone][$area]['customers'],
                    'avg_order' => $cells[$zone][$area]['avg_order'],
                    default     => $cells[$zone][$area]['revenue'],
                };

                if ($val > $maxValue) {
                    $maxValue = $val;
                }
            }
        }

        // Zone totals (proper union of customer_ids)
        $zoneTotals = [];

        foreach ($zones as $zone) {
            $zoneCustomers = [];
            $zoneRevenue = 0.0;
            $zoneOrders = 0;

            foreach ($areas as $area) {
                $bucket = $raw[$zone][$area] ?? null;

                if ($bucket === null) {
                    continue;
                }
                $zoneRevenue += $bucket['revenue'];
                $zoneOrders += $bucket['orders'];

                foreach (array_keys($bucket['customer_ids']) as $cid) {
                    $zoneCustomers[$cid] = true;
                }
            }

            $zoneTotals[$zone] = [
                'revenue'   => round($zoneRevenue, 2),
                'orders'    => $zoneOrders,
                'customers' => count($zoneCustomers),
            ];
        }

        // Area totals (proper union of customer_ids)
        $areaTotals = [];

        foreach ($areas as $area) {
            $areaCustomers = [];
            $areaRevenue = 0.0;
            $areaOrders = 0;

            foreach ($zones as $zone) {
                $bucket = $raw[$zone][$area] ?? null;

                if ($bucket === null) {
                    continue;
                }
                $areaRevenue += $bucket['revenue'];
                $areaOrders += $bucket['orders'];

                foreach (array_keys($bucket['customer_ids']) as $cid) {
                    $areaCustomers[$cid] = true;
                }
            }

            $areaTotals[$area] = [
                'revenue'   => round($areaRevenue, 2),
                'orders'    => $areaOrders,
                'customers' => count($areaCustomers),
            ];
        }

        // Grand total
        $grandCustomers = [];

        foreach ($raw as $areaMap) {
            foreach ($areaMap as $bucket) {
                foreach (array_keys($bucket['customer_ids']) as $cid) {
                    $grandCustomers[$cid] = true;
                }
            }
        }

        return [
            'zones'       => $zones,
            'areas'       => $areas,
            'cells'       => $cells,
            'zone_totals' => $zoneTotals,
            'area_totals' => $areaTotals,
            'max_value'   => $maxValue,
            'grand_total' => [
                'revenue'   => round((float) array_sum(array_column($zoneTotals, 'revenue')), 2),
                'orders'    => (int) array_sum(array_column($zoneTotals, 'orders')),
                'customers' => count($grandCustomers),
            ],
            'metric' => $metric,
            'period' => ['start' => $startDate, 'end' => $endDate],
        ];
    }

    private function geographicCustomerForecast(Collection $saleOrders, int $observedDays, int $forecastDays, string $field): Collection
    {
        return $saleOrders
            ->groupBy(fn (SaleOrder $order): string => (string) ($order->customer?->{$field} ?: 'Unassigned'))
            ->map(function (Collection $orders, string $name) use ($observedDays, $forecastDays, $field): array {
                $ordersCount = $orders->count();
                $qty = (float) $orders->sum(fn (SaleOrder $order): float => (float) $order->items->sum('qty_ordered'));
                $revenue = (float) $orders->sum('total_local');
                $customerCount = $orders->pluck('customer_id')->filter()->unique()->count();
                $avgDailyQty = $qty > 0 ? $qty / $observedDays : 0.0;
                $avgDailyRevenue = $revenue > 0 ? $revenue / $observedDays : 0.0;

                return [
                    $field             => $name,
                    'segment'          => $this->customerSegment($revenue, $ordersCount),
                    'customers_count'  => $customerCount,
                    'orders_count'     => $ordersCount,
                    'history_qty'      => round($qty, 2),
                    'history_revenue'  => round($revenue, 2),
                    'forecast_qty'     => round($avgDailyQty * $forecastDays, 2),
                    'forecast_revenue' => round($avgDailyRevenue * $forecastDays, 2),
                ];
            })
            ->sortByDesc('forecast_revenue')
            ->values();
    }

    private function historicalCustomerCollectionRatio(DateTimeInterface $startDate, DateTimeInterface $endDate): float
    {
        $invoiceClass = Invoice::class;

        if (!class_exists($invoiceClass)) {
            return 0.8;
        }

        $invoices = $invoiceClass::query()
            ->where(function ($query): void {
                $query->where('source_type', SaleOrder::class)
                    ->orWhereNotNull('inventory_sale_order_id');
            })
            ->where('invoice_date', '>=', $startDate->toDateString())
            ->where('invoice_date', '<=', $endDate->toDateString())
            ->get();

        $total = (float) $invoices->sum('base_total');

        if ($total <= 0) {
            return 0.8;
        }

        return max(0.1, min(1.0, round((float) $invoices->sum('base_paid_amount') / $total, 4)));
    }

    private function historicalSupplierPaymentRatio(DateTimeInterface $startDate, DateTimeInterface $endDate): float
    {
        $billClass = Bill::class;

        if (!class_exists($billClass)) {
            return 0.7;
        }

        $bills = $billClass::query()
            ->where(function ($query): void {
                $query->where('source_type', PurchaseOrder::class)
                    ->orWhereNotNull('inventory_purchase_order_id');
            })
            ->where('bill_date', '>=', $startDate->toDateString())
            ->where('bill_date', '<=', $endDate->toDateString())
            ->get();

        $total = (float) $bills->sum('base_total');

        if ($total <= 0) {
            return 0.7;
        }

        return max(0.1, min(1.0, round((float) $bills->sum('base_paid_amount') / $total, 4)));
    }

    private function buildForecastTimeline(
        Collection $productForecast,
        int $forecastDays,
        float $collectionRatio,
        float $supplierPaymentRatio,
    ): array {
        $months = max(3, (int) ceil($forecastDays / 30));
        $categories = [];
        $qtySeries = [];
        $revenueSeries = [];
        $cashInSeries = [];
        $cashOutSeries = [];
        $netSeries = [];
        $forecastEnd = now()->copy()->addDays($forecastDays);

        for ($offset = 0; $offset < $months; $offset++) {
            $monthStart = now()->copy()->startOfMonth()->addMonths($offset);
            $monthEnd = $monthStart->copy()->endOfMonth();
            $periodEnd = $monthEnd->lessThan($forecastEnd) ? $monthEnd : $forecastEnd;
            $days = (float) $monthStart->diffInDaysFiltered(
                fn ($date): bool => $date <= $periodEnd,
                $monthEnd->copy()->addDay(),
            );

            if ($days <= 0) {
                break;
            }

            $monthQty = round((float) $productForecast->sum(fn (array $product): float => (float) $product['avg_daily_qty'] * $days), 2);
            $monthRevenue = round((float) $productForecast->sum(fn (array $product): float => (float) $product['avg_daily_revenue'] * $days), 2);
            $monthOutflow = round((float) $productForecast->sum(function (array $product) use ($forecastDays, $days): float {
                $gapQty = (float) $product['forecast_gap_qty'];

                if ($gapQty <= 0 || $forecastDays <= 0) {
                    return 0.0;
                }

                return ($gapQty / $forecastDays) * $days * ((float) $product['forecast_procurement_cost'] / max(0.0001, $gapQty));
            }), 2);
            $monthCashIn = round($monthRevenue * $collectionRatio, 2);
            $monthCashOut = round($monthOutflow * $supplierPaymentRatio, 2);

            $categories[] = $monthStart->format('M Y');
            $qtySeries[] = $monthQty;
            $revenueSeries[] = $monthRevenue;
            $cashInSeries[] = $monthCashIn;
            $cashOutSeries[] = $monthCashOut;
            $netSeries[] = round($monthCashIn - $monthCashOut, 2);
        }

        return [
            'categories' => $categories,
            'series'     => [
                ['name' => 'Forecast Qty', 'data' => $qtySeries],
                ['name' => 'Forecast Revenue', 'data' => $revenueSeries],
                ['name' => 'Cash In', 'data' => $cashInSeries],
                ['name' => 'Cash Out', 'data' => $cashOutSeries],
                ['name' => 'Net Cash', 'data' => $netSeries],
            ],
            'totals' => [
                'qty'      => round(array_sum($qtySeries), 2),
                'revenue'  => round(array_sum($revenueSeries), 2),
                'cash_in'  => round(array_sum($cashInSeries), 2),
                'cash_out' => round(array_sum($cashOutSeries), 2),
                'cash_net' => round(array_sum($netSeries), 2),
            ],
        ];
    }
}

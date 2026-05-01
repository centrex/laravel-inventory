<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Commands;

use Carbon\Carbon;
use Centrex\Inventory\Enums\{MovementType, SaleOrderStatus};
use Centrex\Inventory\Models\{CustomerProductStat, ProductTrendSnapshot, PurchaseOrder, PurchaseOrderItem, SaleOrder, SaleOrderItem, SaleReturn, SaleReturnItem, StockMovement, StockReceipt, StockReceiptItem, SupplierProductStat, WarehouseProduct};
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SnapshotTrendsCommand extends Command
{
    public $signature = 'inventory:snapshot-trends
        {--date= : Date to snapshot in Y-m-d format (default: yesterday)}
        {--period=daily : Period type: daily|weekly|monthly}
        {--rebuild : Rebuild all snapshots from the beginning of recorded data}
        {--customer-stats : Rebuild customer product stats only}
        {--supplier-stats : Rebuild supplier product stats only}';

    public $description = 'Snapshot product trend metrics and rebuild customer/supplier product statistics for forecasting and profitability analysis.';

    public function handle(): int
    {
        $period = (string) $this->option('period');

        if (!in_array($period, ['daily', 'weekly', 'monthly'], true)) {
            $this->error("Invalid period. Use: daily, weekly, or monthly.");

            return self::FAILURE;
        }

        $customerOnly = (bool) $this->option('customer-stats');
        $supplierOnly = (bool) $this->option('supplier-stats');

        if ($customerOnly) {
            $this->rebuildCustomerProductStats();

            return self::SUCCESS;
        }

        if ($supplierOnly) {
            $this->rebuildSupplierProductStats();

            return self::SUCCESS;
        }

        if ($this->option('rebuild')) {
            $this->rebuildAllSnapshots($period);
        } else {
            $date = $this->option('date')
                ? Carbon::parse((string) $this->option('date'))->startOfDay()
                : now()->subDay()->startOfDay();

            $this->snapshotDate($date, $period);
        }

        $this->rebuildCustomerProductStats();
        $this->rebuildSupplierProductStats();

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function rebuildAllSnapshots(string $period): void
    {
        $earliest = StockMovement::query()->min('moved_at');

        if (!$earliest) {
            $this->warn('No stock movements found; nothing to snapshot.');

            return;
        }

        $start = Carbon::parse($earliest)->startOfDay();
        $end = now()->subDay()->startOfDay();
        $current = $start->copy();

        while ($current->lte($end)) {
            $this->snapshotDate($current, $period);
            $current->addDay();
        }
    }

    private function snapshotDate(Carbon $date, string $period): void
    {
        $periodStart = match ($period) {
            'weekly'  => $date->copy()->startOfWeek(),
            'monthly' => $date->copy()->startOfMonth(),
            default   => $date->copy()->startOfDay(),
        };

        $periodEnd = match ($period) {
            'weekly'  => $date->copy()->endOfWeek(),
            'monthly' => $date->copy()->endOfMonth(),
            default   => $date->copy()->endOfDay(),
        };

        $this->info("Snapshotting {$periodStart->toDateString()} ({$period})…");

        $saleItems = SaleOrderItem::query()
            ->join(
                (new SaleOrder())->getTable() . ' as so',
                'so.id',
                '=',
                (new SaleOrderItem())->getTable() . '.sale_order_id',
            )
            ->whereNotIn('so.status', [SaleOrderStatus::CANCELLED->value, SaleOrderStatus::RETURNED->value])
            ->where('so.document_type', 'order')
            ->whereBetween('so.ordered_at', [$periodStart, $periodEnd])
            ->select([
                (new SaleOrderItem())->getTable() . '.product_id',
                (new SaleOrderItem())->getTable() . '.variant_id',
                'so.warehouse_id',
                'so.customer_id',
                DB::raw('SUM(' . (new SaleOrderItem())->getTable() . '.qty_ordered) as qty_sold'),
                DB::raw('SUM(' . (new SaleOrderItem())->getTable() . '.qty_fulfilled) as qty_fulfilled'),
                DB::raw('SUM(' . (new SaleOrderItem())->getTable() . '.line_total_amount) as revenue_amount'),
                DB::raw('SUM(' . (new SaleOrderItem())->getTable() . '.unit_cost_amount * ' . (new SaleOrderItem())->getTable() . '.qty_ordered) as cogs_amount'),
                DB::raw('COUNT(DISTINCT so.id) as orders_count'),
                DB::raw('COUNT(DISTINCT so.customer_id) as customers_count'),
            ])
            ->groupBy([
                (new SaleOrderItem())->getTable() . '.product_id',
                (new SaleOrderItem())->getTable() . '.variant_id',
                'so.warehouse_id',
            ])
            ->get();

        $returnItems = SaleReturnItem::query()
            ->join(
                (new SaleReturn())->getTable() . ' as sr',
                'sr.id',
                '=',
                (new SaleReturnItem())->getTable() . '.sale_return_id',
            )
            ->whereBetween('sr.returned_at', [$periodStart, $periodEnd])
            ->select([
                (new SaleReturnItem())->getTable() . '.product_id',
                (new SaleReturnItem())->getTable() . '.variant_id',
                'sr.warehouse_id',
                DB::raw('SUM(' . (new SaleReturnItem())->getTable() . '.qty_returned) as qty_returned_sale'),
            ])
            ->groupBy([
                (new SaleReturnItem())->getTable() . '.product_id',
                (new SaleReturnItem())->getTable() . '.variant_id',
                'sr.warehouse_id',
            ])
            ->get()
            ->keyBy(fn ($row): string => "{$row->product_id}|{$row->variant_id}|{$row->warehouse_id}");

        $receiptItems = StockReceiptItem::query()
            ->join(
                (new StockReceipt())->getTable() . ' as grn',
                'grn.id',
                '=',
                (new StockReceiptItem())->getTable() . '.stock_receipt_id',
            )
            ->where('grn.status', 'posted')
            ->whereBetween('grn.received_at', [$periodStart, $periodEnd])
            ->select([
                (new StockReceiptItem())->getTable() . '.product_id',
                (new StockReceiptItem())->getTable() . '.variant_id',
                'grn.warehouse_id',
                DB::raw('SUM(' . (new StockReceiptItem())->getTable() . '.qty_received) as qty_purchased'),
            ])
            ->groupBy([
                (new StockReceiptItem())->getTable() . '.product_id',
                (new StockReceiptItem())->getTable() . '.variant_id',
                'grn.warehouse_id',
            ])
            ->get()
            ->keyBy(fn ($row): string => "{$row->product_id}|{$row->variant_id}|{$row->warehouse_id}");

        $stockLevels = WarehouseProduct::query()
            ->get()
            ->keyBy(fn (WarehouseProduct $wp): string => "{$wp->product_id}|{$wp->variant_id}|{$wp->warehouse_id}");

        foreach ($saleItems as $row) {
            $key = "{$row->product_id}|{$row->variant_id}|{$row->warehouse_id}";
            $revenue = (float) $row->revenue_amount;
            $cogs = (float) $row->cogs_amount;
            $grossProfit = $revenue - $cogs;
            $grossMargin = $revenue > 0 ? round($grossProfit / $revenue * 100, 4) : 0.0;
            $qtySold = (float) $row->qty_sold;
            $avgSellPrice = $qtySold > 0 ? round($revenue / $qtySold, 4) : 0.0;
            $avgCost = $qtySold > 0 ? round($cogs / $qtySold, 4) : 0.0;
            $returnRow = $returnItems->get($key);
            $receiptRow = $receiptItems->get($key);
            $stock = $stockLevels->get($key);

            ProductTrendSnapshot::updateOrCreate(
                [
                    'product_id'    => $row->product_id,
                    'variant_id'    => $row->variant_id,
                    'warehouse_id'  => $row->warehouse_id,
                    'snapshot_date' => $periodStart->toDateString(),
                    'period'        => $period,
                ],
                [
                    'qty_sold'              => $qtySold,
                    'qty_purchased'         => $receiptRow ? (float) $receiptRow->qty_purchased : 0.0,
                    'qty_returned_sale'     => $returnRow ? (float) $returnRow->qty_returned_sale : 0.0,
                    'qty_returned_purchase' => 0.0,
                    'revenue_amount'        => round($revenue, 4),
                    'cogs_amount'           => round($cogs, 4),
                    'gross_profit_amount'   => round($grossProfit, 4),
                    'gross_margin_pct'      => $grossMargin,
                    'avg_sell_price'        => $avgSellPrice,
                    'avg_cost_amount'       => $avgCost,
                    'wac_snapshot'          => $stock ? (float) $stock->wac_amount : 0.0,
                    'qty_on_hand_snapshot'  => $stock ? (float) $stock->qty_on_hand : 0.0,
                    'orders_count'          => (int) $row->orders_count,
                    'customers_count'       => (int) $row->customers_count,
                ],
            );
        }
    }

    private function rebuildCustomerProductStats(): void
    {
        $this->info('Rebuilding customer product stats…');

        SaleOrderItem::query()
            ->join(
                (new SaleOrder())->getTable() . ' as so',
                'so.id',
                '=',
                (new SaleOrderItem())->getTable() . '.sale_order_id',
            )
            ->whereNotIn('so.status', [SaleOrderStatus::CANCELLED->value, SaleOrderStatus::RETURNED->value])
            ->where('so.document_type', 'order')
            ->whereNotNull('so.customer_id')
            ->select([
                'so.customer_id',
                (new SaleOrderItem())->getTable() . '.product_id',
                (new SaleOrderItem())->getTable() . '.variant_id',
                DB::raw('COUNT(DISTINCT so.id) as total_orders'),
                DB::raw('SUM(' . (new SaleOrderItem())->getTable() . '.qty_ordered) as total_qty_ordered'),
                DB::raw('SUM(' . (new SaleOrderItem())->getTable() . '.qty_fulfilled) as total_qty_fulfilled'),
                DB::raw('SUM(' . (new SaleOrderItem())->getTable() . '.line_total_amount) as total_revenue_amount'),
                DB::raw('AVG(' . (new SaleOrderItem())->getTable() . '.unit_price_amount) as avg_unit_price_amount'),
                DB::raw('MIN(so.ordered_at) as first_ordered_at'),
                DB::raw('MAX(so.ordered_at) as last_ordered_at'),
            ])
            ->groupBy([
                'so.customer_id',
                (new SaleOrderItem())->getTable() . '.product_id',
                (new SaleOrderItem())->getTable() . '.variant_id',
            ])
            ->chunkById(500, function (Collection $rows): void {
                foreach ($rows as $row) {
                    $returnQty = (float) SaleReturnItem::query()
                        ->join(
                            (new SaleReturn())->getTable() . ' as sr',
                            'sr.id',
                            '=',
                            (new SaleReturnItem())->getTable() . '.sale_return_id',
                        )
                        ->where('sr.customer_id', $row->customer_id)
                        ->where((new SaleReturnItem())->getTable() . '.product_id', $row->product_id)
                        ->where((new SaleReturnItem())->getTable() . '.variant_id', $row->variant_id)
                        ->sum((new SaleReturnItem())->getTable() . '.qty_returned');

                    $totalOrdered = (float) $row->total_qty_ordered;
                    $returnRate = $totalOrdered > 0 ? round($returnQty / $totalOrdered * 100, 4) : 0.0;

                    $intervalDays = $this->calcCustomerProductInterval(
                        (int) $row->customer_id,
                        (int) $row->product_id,
                        $row->variant_id,
                    );

                    CustomerProductStat::updateOrCreate(
                        [
                            'customer_id' => $row->customer_id,
                            'product_id'  => $row->product_id,
                            'variant_id'  => $row->variant_id,
                        ],
                        [
                            'total_orders'            => (int) $row->total_orders,
                            'total_qty_ordered'       => $totalOrdered,
                            'total_qty_fulfilled'     => (float) $row->total_qty_fulfilled,
                            'total_revenue_amount'    => (float) $row->total_revenue_amount,
                            'avg_unit_price_amount'   => (float) $row->avg_unit_price_amount,
                            'avg_order_interval_days' => $intervalDays,
                            'total_return_qty'        => $returnQty,
                            'return_rate_pct'         => $returnRate,
                            'first_ordered_at'        => $row->first_ordered_at,
                            'last_ordered_at'         => $row->last_ordered_at,
                        ],
                    );
                }
            }, (new SaleOrderItem())->getTable() . '.id');
    }

    private function calcCustomerProductInterval(int $customerId, int $productId, mixed $variantId): ?float
    {
        $dates = SaleOrder::query()
            ->join(
                (new SaleOrderItem())->getTable() . ' as soi',
                'soi.sale_order_id',
                '=',
                (new SaleOrder())->getTable() . '.id',
            )
            ->where((new SaleOrder())->getTable() . '.customer_id', $customerId)
            ->where('soi.product_id', $productId)
            ->where('soi.variant_id', $variantId)
            ->whereNotIn((new SaleOrder())->getTable() . '.status', [SaleOrderStatus::CANCELLED->value])
            ->orderBy((new SaleOrder())->getTable() . '.ordered_at')
            ->pluck((new SaleOrder())->getTable() . '.ordered_at')
            ->filter()
            ->values();

        if ($dates->count() < 2) {
            return null;
        }

        $gaps = [];

        for ($i = 1; $i < $dates->count(); $i++) {
            $gaps[] = Carbon::parse($dates[$i - 1])->diffInDays(Carbon::parse($dates[$i]));
        }

        return round(array_sum($gaps) / count($gaps), 2);
    }

    private function rebuildSupplierProductStats(): void
    {
        $this->info('Rebuilding supplier product stats…');

        PurchaseOrderItem::query()
            ->join(
                (new PurchaseOrder())->getTable() . ' as po',
                'po.id',
                '=',
                (new PurchaseOrderItem())->getTable() . '.purchase_order_id',
            )
            ->whereNotIn('po.status', ['cancelled'])
            ->where('po.document_type', 'order')
            ->select([
                'po.supplier_id',
                (new PurchaseOrderItem())->getTable() . '.product_id',
                (new PurchaseOrderItem())->getTable() . '.variant_id',
                DB::raw('COUNT(DISTINCT po.id) as total_orders'),
                DB::raw('SUM(' . (new PurchaseOrderItem())->getTable() . '.qty_ordered) as total_qty_ordered'),
                DB::raw('SUM(' . (new PurchaseOrderItem())->getTable() . '.qty_received) as total_qty_received'),
                DB::raw('SUM(' . (new PurchaseOrderItem())->getTable() . '.line_total_amount) as total_cost_amount'),
                DB::raw('AVG(' . (new PurchaseOrderItem())->getTable() . '.unit_price_amount) as avg_unit_cost_amount'),
                DB::raw('MIN(' . (new PurchaseOrderItem())->getTable() . '.unit_price_amount) as min_unit_cost_amount'),
                DB::raw('MAX(' . (new PurchaseOrderItem())->getTable() . '.unit_price_amount) as max_unit_cost_amount'),
                DB::raw('MIN(po.ordered_at) as first_ordered_at'),
                DB::raw('MAX(po.ordered_at) as last_ordered_at'),
            ])
            ->groupBy([
                'po.supplier_id',
                (new PurchaseOrderItem())->getTable() . '.product_id',
                (new PurchaseOrderItem())->getTable() . '.variant_id',
            ])
            ->chunkById(500, function (Collection $rows): void {
                foreach ($rows as $row) {
                    [$avgLeadTime, $onTimeRate] = $this->calcSupplierLeadTimeMetrics(
                        (int) $row->supplier_id,
                        (int) $row->product_id,
                        $row->variant_id,
                    );

                    $totalOrdered = (float) $row->total_qty_ordered;
                    $totalReceived = (float) $row->total_qty_received;
                    $fulfillmentRate = $totalOrdered > 0
                        ? round($totalReceived / $totalOrdered * 100, 4)
                        : 0.0;

                    SupplierProductStat::updateOrCreate(
                        [
                            'supplier_id' => $row->supplier_id,
                            'product_id'  => $row->product_id,
                            'variant_id'  => $row->variant_id,
                        ],
                        [
                            'total_orders'             => (int) $row->total_orders,
                            'total_qty_ordered'        => $totalOrdered,
                            'total_qty_received'       => $totalReceived,
                            'total_cost_amount'        => (float) $row->total_cost_amount,
                            'avg_unit_cost_amount'     => (float) $row->avg_unit_cost_amount,
                            'min_unit_cost_amount'     => (float) $row->min_unit_cost_amount,
                            'max_unit_cost_amount'     => (float) $row->max_unit_cost_amount,
                            'avg_lead_time_days'       => $avgLeadTime,
                            'on_time_receipt_rate_pct' => $onTimeRate,
                            'fulfillment_rate_pct'     => $fulfillmentRate,
                            'first_ordered_at'         => $row->first_ordered_at,
                            'last_ordered_at'          => $row->last_ordered_at,
                        ],
                    );
                }
            }, (new PurchaseOrderItem())->getTable() . '.id');
    }

    private function calcSupplierLeadTimeMetrics(int $supplierId, int $productId, mixed $variantId): array
    {
        $pos = PurchaseOrder::query()
            ->join(
                (new PurchaseOrderItem())->getTable() . ' as poi',
                'poi.purchase_order_id',
                '=',
                (new PurchaseOrder())->getTable() . '.id',
            )
            ->join(
                (new StockReceipt())->getTable() . ' as grn',
                'grn.purchase_order_id',
                '=',
                (new PurchaseOrder())->getTable() . '.id',
            )
            ->where((new PurchaseOrder())->getTable() . '.supplier_id', $supplierId)
            ->where('poi.product_id', $productId)
            ->where('poi.variant_id', $variantId)
            ->where('grn.status', 'posted')
            ->whereNotNull((new PurchaseOrder())->getTable() . '.ordered_at')
            ->select([
                (new PurchaseOrder())->getTable() . '.ordered_at',
                (new PurchaseOrder())->getTable() . '.expected_at',
                'grn.received_at',
            ])
            ->get();

        if ($pos->isEmpty()) {
            return [null, 0.0];
        }

        $leadTimes = [];
        $onTimeCount = 0;

        foreach ($pos as $po) {
            if ($po->ordered_at && $po->received_at) {
                $leadTimes[] = Carbon::parse($po->ordered_at)->diffInDays(Carbon::parse($po->received_at));
            }

            if ($po->expected_at && $po->received_at) {
                if (Carbon::parse($po->received_at)->lte(Carbon::parse($po->expected_at))) {
                    $onTimeCount++;
                }
            }
        }

        $avgLeadTime = !empty($leadTimes)
            ? round(array_sum($leadTimes) / count($leadTimes), 2)
            : null;

        $onTimeRate = $pos->count() > 0
            ? round($onTimeCount / $pos->count() * 100, 4)
            : 0.0;

        return [$avgLeadTime, $onTimeRate];
    }
}

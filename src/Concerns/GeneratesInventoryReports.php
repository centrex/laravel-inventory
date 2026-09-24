<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Concerns;

use Centrex\Inventory\Enums\{SaleOrderStatus, StockReceiptStatus};
use Centrex\Inventory\Models\{Customer, SaleOrder, SaleOrderItem, StockMovement, StockReceipt, StockReceiptItem, Supplier, WarehouseProduct};
use DateTimeInterface;
use Illuminate\Support\{Carbon, Collection};

trait GeneratesInventoryReports
{
    public function stockValuationReport(?int $warehouseId = null): Collection
    {
        return WarehouseProduct::with(['product', 'warehouse'])
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->where('qty_on_hand', '>', 0)
            ->get()
            ->map(fn (WarehouseProduct $wp) => [
                'warehouse'          => $wp->warehouse->name,
                'sku'                => $wp->product->sku,
                'product'            => $wp->product->name,
                'qty_on_hand'        => (float) $wp->qty_on_hand,
                'qty_reserved'       => (float) $wp->qty_reserved,
                'qty_available'      => $wp->qtyAvailable(),
                'wac_amount'         => (float) $wp->wac_amount,
                'total_value_amount' => $wp->totalValue(),
            ]);
    }

    public function customerHistory(int $customerId, int $limit = 10): Collection
    {
        return SaleOrder::with(['warehouse', 'items.product'])
            ->where('customer_id', $customerId)
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function customerCreditSnapshot(int $customerId): array
    {
        $customer = Customer::findOrFail($customerId);
        $exposure = $this->customerOutstandingExposure($customer->id);
        $limit = (float) $customer->credit_limit_amount;

        return [
            'customer_id'             => $customer->id,
            'credit_limit_amount'     => $limit,
            'outstanding_exposure'    => $exposure,
            'available_credit_amount' => round($limit - $exposure, 4),
            'is_over_limit'           => $limit > 0
                ? $exposure > $limit + $this->qtyTolerance()
                : $exposure > $this->qtyTolerance(),
        ];
    }

    public function supplierCreditSnapshot(int $supplierId): array
    {
        $supplier = Supplier::findOrFail($supplierId);
        $exposure = $this->supplierOutstandingExposure($supplier->id);
        $limit = (float) $supplier->credit_limit_amount;

        return [
            'supplier_id'             => $supplier->id,
            'credit_limit_amount'     => $limit,
            'outstanding_exposure'    => $exposure,
            'available_credit_amount' => round($limit - $exposure, 4),
            'is_over_limit'           => $limit > 0
                ? $exposure > $limit + $this->qtyTolerance()
                : $exposure > $this->qtyTolerance(),
        ];
    }

    public function getMovementHistory(int $productId, int $warehouseId, ?string $from = null, ?string $to = null): Collection
    {
        return StockMovement::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->when($from, fn ($q) => $q->where('moved_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('moved_at', '<=', $to))
            ->orderBy('moved_at')
            ->get();
    }

    /**
     * Buckets current on-hand stock by the age of the receipt it's actually traced back to,
     * reconstructed by replaying purchases and sales (plus whatever else the movement ledger
     * has — transfers, adjustments, returns) together as a FIFO queue: every inbound event
     * pushes a dated batch, every outbound event consumes the oldest batches first. Whatever
     * remains in the queue once the ledger is exhausted is the current on-hand stock, split
     * by the date each surviving unit actually arrived.
     *
     * This matters because looking only at the *last* receipt (as an earlier version of this
     * report did) is wrong whenever a product has more than one receipt on hand at once: e.g.
     * 100 units received 90 days ago plus 50 more received 5 days ago, with nothing sold in
     * between, is 150 units on hand — but naively aging all 150 from the 5-day-old receipt
     * hides that two-thirds of it is actually 90 days old. Replaying purchases *and* sales
     * together against the ledger is what correctly attributes remaining qty back to its batch.
     *
     * The ledger itself is assembled from two sources (see {@see stockAgingLedgers()}): the
     * inv_stock_movements audit trail where it exists, backfilled from the underlying posted
     * GRNs and fulfilled sale-order items wherever a warehouse×product×variant's movement
     * history doesn't fully cover it — e.g. after a partial migration from a previous system
     * that carried over full purchase/sale records but not the derived movement ledger.
     *
     * Warehouse×product×variant combinations where qty_on_hand still exceeds what that
     * combined ledger accounts for (no receipt or movement traces back far enough) have that
     * shortfall bucketed as 'unknown' rather than guessed at.
     */
    public function stockAgingReport(?int $warehouseId = null): Collection
    {
        $stock = WarehouseProduct::with(['product', 'warehouse'])
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->where('qty_on_hand', '>', 0)
            ->get();

        if ($stock->isEmpty()) {
            return collect();
        }

        $ledgers = $this->stockAgingLedgers($stock);
        $now = now();

        return $stock->map(function (WarehouseProduct $wp) use ($ledgers, $now) {
            $key = "{$wp->warehouse_id}:{$wp->product_id}:" . ($wp->variant_id ?? 0);
            $qtyOnHand = (float) $wp->qty_on_hand;
            $batches = $this->fifoRemainingBatches($ledgers[$key] ?? [], $qtyOnHand);
            $wacAmount = (float) $wp->wac_amount;

            $buckets = $this->emptyAgingBuckets();
            $oldestDays = null;

            foreach ($batches as $batch) {
                $days = $batch['moved_at'] ? (int) $batch['moved_at']->diffInDays($now) : null;
                $bucket = $this->agingBucket($days);

                $buckets[$bucket]['qty'] += $batch['qty'];
                $buckets[$bucket]['value'] += round($batch['qty'] * $wacAmount, 4);

                if ($days !== null && ($oldestDays === null || $days > $oldestDays)) {
                    $oldestDays = $days;
                }
            }

            return [
                'warehouse'            => $wp->warehouse->name,
                'sku'                  => $wp->product->sku,
                'product'              => $wp->product->name,
                'qty_on_hand'          => $qtyOnHand,
                'wac_amount'           => $wacAmount,
                'total_value_amount'   => $wp->totalValue(),
                'oldest_days_in_stock' => $oldestDays,
                // Per-bucket ['qty' => float, 'value' => float], keyed 0-30 / 31-60 / 61-90 / 90+ / unknown.
                'buckets' => $buckets,
            ];
        });
    }

    /** Total stock value per aging bucket (0-30 / 31-60 / 61-90 / 90+ / unknown days since the traced receipt). */
    public function stockAgingSummary(?int $warehouseId = null): array
    {
        $totals = $this->emptyAgingBuckets();

        foreach ($this->stockAgingReport($warehouseId) as $row) {
            foreach ($row['buckets'] as $bucket => $amounts) {
                $totals[$bucket]['qty'] += $amounts['qty'];
                $totals[$bucket]['value'] += $amounts['value'];
            }
        }

        return array_map(static fn (array $bucket): float => $bucket['value'], $totals);
    }

    /**
     * Builds the per-(warehouse×product×variant) event ledger that {@see stockAgingReport()}
     * replays as FIFO batches, from two sources:
     *
     * 1. The inv_stock_movements audit trail — authoritative and precise wherever it exists.
     * 2. A backfill from the underlying source documents, for anything the movement trail
     *    doesn't cover: posted GRN items (purchases, inbound) and fulfilled sale-order items
     *    (sales, outbound). This is what lets aging stay accurate after a partial data
     *    migration that carried over full purchase/sale records but not the derived movement
     *    ledger — the ledger is meant to be a derived audit trail of these same documents, so
     *    reconstructing missing entries straight from the documents is exact, not a guess.
     *
     * A document only contributes a backfilled entry if no real movement already references it
     * (matched via StockMovement.reference_type/reference_id) — otherwise a receipt or sale
     * that already has its own movement rows would be double-counted.
     *
     * Backfilled purchase batches are dated by the GRN's received_at; backfilled sale batches
     * are dated by the sale order's ordered_at, since migrated historical orders rarely carry a
     * separate fulfillment timestamp — for an already-completed order the two are close enough
     * for aging purposes.
     *
     * @param  Collection<int, WarehouseProduct>  $stock
     * @return array<string, array<int, array{direction: string, qty: float, moved_at: ?Carbon}>> keyed "warehouseId:productId:variantId", oldest first
     */
    private function stockAgingLedgers(Collection $stock): array
    {
        $warehouseIds = $stock->pluck('warehouse_id')->unique();
        $productIds = $stock->pluck('product_id')->unique();

        $ledgers = [];
        $coveredReceipts = [];
        $coveredSaleOrders = [];

        StockMovement::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('product_id', $productIds)
            ->get(['warehouse_id', 'product_id', 'variant_id', 'direction', 'qty', 'moved_at', 'reference_type', 'reference_id'])
            ->each(function (StockMovement $m) use (&$ledgers, &$coveredReceipts, &$coveredSaleOrders): void {
                $key = "{$m->warehouse_id}:{$m->product_id}:" . ($m->variant_id ?? 0);

                $ledgers[$key][] = [
                    'direction' => $m->direction,
                    'qty'       => (float) $m->qty,
                    'moved_at'  => $m->moved_at ? Carbon::parse($m->moved_at) : null,
                ];

                if ($m->reference_type === StockReceipt::class) {
                    $coveredReceipts["{$key}:{$m->reference_id}"] = true;
                } elseif ($m->reference_type === SaleOrder::class) {
                    $coveredSaleOrders["{$key}:{$m->reference_id}"] = true;
                }
            });

        StockReceiptItem::query()
            ->whereIn('product_id', $productIds)
            ->whereHas('stockReceipt', fn ($q) => $q->where('status', StockReceiptStatus::POSTED->value)->whereIn('warehouse_id', $warehouseIds))
            ->with(['stockReceipt:id,warehouse_id,received_at'])
            ->get(['product_id', 'variant_id', 'stock_receipt_id', 'qty_received', 'qty_damaged', 'qty_lost'])
            ->each(function (StockReceiptItem $item) use (&$ledgers, $coveredReceipts): void {
                $receipt = $item->stockReceipt;

                if (!$receipt) {
                    return;
                }

                $key = "{$receipt->warehouse_id}:{$item->product_id}:" . ($item->variant_id ?? 0);

                if (isset($coveredReceipts["{$key}:{$receipt->id}"])) {
                    return; // already represented by a real movement row
                }

                $qtyGood = max(0.0, (float) $item->qty_received - (float) $item->qty_damaged - (float) $item->qty_lost);

                if ($qtyGood <= 0.0) {
                    return;
                }

                $ledgers[$key][] = [
                    'direction' => 'in',
                    'qty'       => $qtyGood,
                    'moved_at'  => $receipt->received_at ? Carbon::parse($receipt->received_at) : null,
                ];
            });

        SaleOrderItem::query()
            ->whereIn('product_id', $productIds)
            ->where('qty_fulfilled', '>', 0)
            ->whereHas('saleOrder', fn ($q) => $q->whereIn('warehouse_id', $warehouseIds))
            ->with(['saleOrder:id,warehouse_id,ordered_at'])
            ->get(['product_id', 'variant_id', 'sale_order_id', 'qty_fulfilled'])
            ->each(function (SaleOrderItem $item) use (&$ledgers, $coveredSaleOrders): void {
                $saleOrder = $item->saleOrder;

                if (!$saleOrder) {
                    return;
                }

                $key = "{$saleOrder->warehouse_id}:{$item->product_id}:" . ($item->variant_id ?? 0);

                if (isset($coveredSaleOrders["{$key}:{$saleOrder->id}"])) {
                    return; // already represented by a real movement row
                }

                $ledgers[$key][] = [
                    'direction' => 'out',
                    'qty'       => (float) $item->qty_fulfilled,
                    'moved_at'  => $saleOrder->ordered_at ? Carbon::parse($saleOrder->ordered_at) : null,
                ];
            });

        foreach ($ledgers as $key => $entries) {
            usort($entries, static fn (array $a, array $b): int => ($a['moved_at']?->timestamp ?? 0) <=> ($b['moved_at']?->timestamp ?? 0));
            $ledgers[$key] = $entries;
        }

        return $ledgers;
    }

    /**
     * Replays one product's combined event ledger (oldest first) as a FIFO queue: every
     * inbound event pushes a dated batch, every outbound event consumes the oldest batches
     * first. Returns what's left — the current on-hand stock, decomposed by the date it
     * actually arrived.
     *
     * @param  array<int, array{direction: string, qty: float, moved_at: ?Carbon}>  $movements  oldest first
     * @return array<int, array{qty: float, moved_at: ?Carbon}>
     */
    private function fifoRemainingBatches(array $movements, float $qtyOnHand): array
    {
        $tolerance = $this->qtyTolerance();
        $queue = [];

        foreach ($movements as $movement) {
            $qty = (float) $movement['qty'];

            if ($qty <= $tolerance) {
                continue;
            }

            if ($movement['direction'] === 'in') {
                $queue[] = ['qty' => $qty, 'moved_at' => $movement['moved_at']];

                continue;
            }

            // Outbound: consume the oldest batches first.
            while ($qty > $tolerance && $queue !== []) {
                $take = min($qty, $queue[0]['qty']);
                $queue[0]['qty'] -= $take;
                $qty -= $take;

                if ($queue[0]['qty'] <= $tolerance) {
                    array_shift($queue);
                }
            }
        }

        $ledgerQty = array_sum(array_column($queue, 'qty'));
        $diff = round($qtyOnHand - $ledgerQty, 4);

        if ($diff > $tolerance) {
            // qty_on_hand exceeds what the ledger accounts for (e.g. stock that predates
            // movement tracking) — attribute the gap to an untraceable, undated batch.
            $queue[] = ['qty' => $diff, 'moved_at' => null];
        } elseif ($diff < -$tolerance) {
            // Ledger says more remains than the actual record (e.g. a damaged-bin deduction
            // that isn't modeled as its own movement type) — trim the oldest batches down to
            // qty_on_hand rather than over-report; untracked shrinkage skews old stock first.
            $excess = -$diff;

            while ($excess > $tolerance && $queue !== []) {
                $take = min($excess, $queue[0]['qty']);
                $queue[0]['qty'] -= $take;
                $excess -= $take;

                if ($queue[0]['qty'] <= $tolerance) {
                    array_shift($queue);
                }
            }
        }

        return $queue;
    }

    /** @return array<string, array{qty: float, value: float}> */
    private function emptyAgingBuckets(): array
    {
        return [
            '0-30'    => ['qty' => 0.0, 'value' => 0.0],
            '31-60'   => ['qty' => 0.0, 'value' => 0.0],
            '61-90'   => ['qty' => 0.0, 'value' => 0.0],
            '90+'     => ['qty' => 0.0, 'value' => 0.0],
            'unknown' => ['qty' => 0.0, 'value' => 0.0],
        ];
    }

    /**
     * Buckets each customer's outstanding sale-order due_amount by age since the order date.
     *
     * Uses the same "which orders count as outstanding" rule as {@see customerOutstandingExposure()}:
     * open orders (confirmed/processing/partial) always count, fulfilled orders only count when
     * they carry a linked accounting invoice (i.e. were sold on credit, not cash/COD).
     *
     * @param  string|DateTimeInterface|null  $fromDate  Only include orders placed on or after
     *                                                   this date — a lower bound on which
     *                                                   debts count, not a historical viewpoint.
     *                                                   Days-overdue/bucket are still computed
     *                                                   relative to today regardless. Defaults
     *                                                   to no lower bound (every outstanding order).
     */
    public function dueAgingReport(?int $customerId = null, string|DateTimeInterface|null $fromDate = null): Collection
    {
        $openStatuses = [
            SaleOrderStatus::CONFIRMED->value,
            SaleOrderStatus::PROCESSING->value,
            SaleOrderStatus::PARTIAL->value,
        ];
        $tolerance = $this->qtyTolerance();
        $now = now();

        $orders = SaleOrder::with('customer')
            ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
            ->where('due_amount', '>', $tolerance)
            ->when($fromDate, fn ($q) => $q->where('ordered_at', '>=', Carbon::parse($fromDate)->startOfDay()))
            ->where(function ($q) use ($openStatuses): void {
                $q->whereIn('status', $openStatuses)
                    ->orWhere(function ($q2): void {
                        $q2->where('status', SaleOrderStatus::FULFILLED->value)
                            ->whereNotNull('accounting_invoice_id');
                    });
            })
            ->orderBy('ordered_at')
            ->get();

        return $orders->map(function (SaleOrder $so) use ($now) {
            $days = $so->ordered_at ? (int) $so->ordered_at->diffInDays($now) : null;

            return [
                'customer_id'  => $so->customer_id,
                'customer'     => $so->customer?->organization_name ?: $so->customer?->name,
                'so_number'    => $so->so_number,
                'ordered_at'   => $so->ordered_at,
                'due_amount'   => (float) $so->due_amount,
                'days_overdue' => $days,
                'age_bucket'   => $this->agingBucket($days),
            ];
        });
    }

    /** Total due amount per aging bucket (0-30 / 31-60 / 61-90 / 90+ / unknown days since order date). */
    public function dueAgingSummary(?int $customerId = null, string|DateTimeInterface|null $fromDate = null): array
    {
        $buckets = ['0-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '90+' => 0.0, 'unknown' => 0.0];

        foreach ($this->dueAgingReport($customerId, $fromDate) as $row) {
            $buckets[$row['age_bucket']] += $row['due_amount'];
        }

        return $buckets;
    }

    private function agingBucket(?int $days): string
    {
        return match (true) {
            $days === null => 'unknown',
            $days <= 30    => '0-30',
            $days <= 60    => '31-60',
            $days <= 90    => '61-90',
            default        => '90+',
        };
    }
}

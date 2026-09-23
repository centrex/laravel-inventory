<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Concerns;

use Centrex\Inventory\Enums\{MovementType, SaleOrderStatus};
use Centrex\Inventory\Exceptions\{InsufficientStockException, InvalidTransitionException};
use Centrex\Inventory\Models\{Lot, SaleOrder, SerialNumber, WarehouseProduct};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\{DB, Gate};

/** Sale order lifecycle: confirm, reserve stock, fulfil, cancel. */
trait ManagesSaleOrderLifecycle
{
    /**
     * Confirms a draft sale order. When the order's price tier is auto-reserving (see
     * shouldAutoReserveOnConfirm() — inventory.auto_reserve_price_tiers, b2c_ecom by default, or
     * inventory.auto_reserve_on_confirm=true for every tier), this also reserves stock in the
     * same call — the order goes straight from draft to processing, skipping the separate manual
     * "Reserve" step. If any line is short on available stock, reserveStockItems() throws
     * InsufficientStockException and the whole confirmation rolls back — the order stays in
     * DRAFT rather than being confirmed against stock it can't cover.
     *
     * @throws InsufficientStockException
     */
    public function confirmSaleOrder(int $soId): SaleOrder
    {
        return DB::transaction(function () use ($soId): SaleOrder {
            $so = SaleOrder::lockForUpdate()->findOrFail($soId);
            $this->assertSaleOrderAccess($so);
            $this->assertTransition($so->status, SaleOrderStatus::CONFIRMED, "sale order #{$soId}");
            $this->assertHighValueConfirmAuthorized($so);
            $so->update(['status' => SaleOrderStatus::CONFIRMED, 'confirmed_at' => now()]);

            if ($this->shouldAutoReserveOnConfirm($so)) {
                $this->reserveStockItems($so);
                $so->update(['status' => SaleOrderStatus::PROCESSING, 'reserved_at' => now()]);
            }

            return $so->refresh();
        });
    }

    /**
     * inventory.auto_reserve_on_confirm=true auto-reserves every tier (legacy global switch,
     * defaults to false). Otherwise, only price tiers listed in
     * inventory.auto_reserve_price_tiers (b2c_ecom by default) auto-reserve — everything else
     * keeps the two-step confirm-then-reserve workflow, or gets reserved explicitly by the
     * caller (e.g. the POS terminal checkout, which always passes reserve=true to
     * CartCheckoutService regardless of this setting).
     */
    private function shouldAutoReserveOnConfirm(SaleOrder $so): bool
    {
        if (config('inventory.auto_reserve_on_confirm', false)) {
            return true;
        }

        $tiers = (array) config('inventory.auto_reserve_price_tiers', []);

        return in_array($so->price_tier_code, $tiers, true);
    }

    /**
     * Sale orders at or above inventory.sale_order_high_value_threshold require confirmation by
     * a user holding inventory.sale-orders.confirm-high-value (General Manager / System
     * Administrator by default) — a stricter check layered on top of the ordinary confirm
     * ability, since a large order shouldn't be confirmable by whoever merely has the everyday
     * sales-confirm permission. Skipped for console callers (seeders, artisan, tinker) — there's
     * no web user to check, and CLI access is already a higher trust boundary.
     *
     * Public so any code path that can move a sale order into CONFIRMED outside of
     * confirmSaleOrder() (e.g. DispatchTerminalPage's manual order-status override) can enforce
     * the same gate instead of silently bypassing it.
     */
    public function assertHighValueConfirmAuthorized(SaleOrder $so): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        $threshold = (float) config('inventory.sale_order_high_value_threshold', 0);

        if ($threshold <= 0 || (float) $so->total_amount < $threshold) {
            return;
        }

        if (Gate::forUser(auth()->user())->denies('inventory.sale-orders.confirm-high-value')) {
            throw new AuthorizationException(
                "Sale order #{$so->so_number} totals " . number_format((float) $so->total_amount, 2)
                . ' and can only be confirmed by a General Manager or System Administrator.',
            );
        }
    }

    /**
     * Reserve stock: increment qty_reserved for each line item.
     *
     * The sale order row is locked and its status re-checked inside the transaction (rather
     * than before it) so that two overlapping requests for the same order — e.g. a
     * double-click on a slow connection — serialize instead of both passing the "not already
     * reserved" check and double-incrementing qty_reserved.
     *
     * If any line is short on available stock, reserveStockItems() throws
     * InsufficientStockException and the order stays in CONFIRMED rather than moving to
     * PROCESSING against stock it can't cover.
     *
     * @throws InsufficientStockException
     */
    public function reserveStock(int $soId): SaleOrder
    {
        return DB::transaction(function () use ($soId): SaleOrder {
            $so = SaleOrder::lockForUpdate()->findOrFail($soId);
            $this->assertSaleOrderAccess($so);

            if ($so->status === SaleOrderStatus::PROCESSING) {
                throw new InvalidTransitionException("Stock is already reserved for sale order #{$soId}.");
            }

            if ($so->status !== SaleOrderStatus::CONFIRMED) {
                throw new InvalidTransitionException("Cannot reserve stock for sale order in status [{$so->status->value}].");
            }

            $this->reserveStockItems($so);
            $so->update(['status' => SaleOrderStatus::PROCESSING, 'reserved_at' => now()]);

            return $so->refresh();
        });
    }

    /**
     * Increments qty_reserved for each line item of $so (auto-creating a WarehouseProduct row
     * where none exists yet). Shared by reserveStock() and confirmSaleOrder()'s optional
     * auto-reserve-on-confirm step — the caller is responsible for the surrounding
     * transaction/row lock and for updating $so->status afterward.
     *
     * Checks every line's availability before reserving any of them, and throws
     * InsufficientStockException listing every short line if any is short — reservation is
     * all-or-nothing so a shortfall can never be silently oversold, and the caller's
     * transaction rolls back cleanly. Lines are tallied per warehouse product (rather than
     * checked independently) so that two lines drawing on the same product/variant correctly
     * compete for the same available quantity instead of both being approved against it.
     *
     * @throws InsufficientStockException
     */
    private function reserveStockItems(SaleOrder $so): void
    {
        $items = $so->items()->lockForUpdate()->get();
        $requested = [];
        $pairs = [];

        foreach ($items as $item) {
            $key = "{$item->product_id}:{$item->variant_id}";

            if (!isset($requested[$key])) {
                $pairs[$key] = [$item->product_id, $item->variant_id];
                $requested[$key] = 0.0;
            }

            $requested[$key] += (float) $item->qty_ordered;
        }

        if ($pairs === []) {
            return;
        }

        // One locked query for every distinct product/variant on this order, instead of a
        // lockForUpdate()->first() per line inside the loop above. Under concurrent
        // checkouts for the same hot-selling products, N separate per-row lock-acquisition
        // round trips serialized requests behind one another one row at a time; a single
        // batched query still locks exactly the same rows (no more, no less — the grouped
        // orWhere below matches only the requested product+variant pairs, not every variant
        // of a product) but does it in one round trip.
        $warehouseProducts = WarehouseProduct::where('warehouse_id', $so->warehouse_id)
            ->where(function ($query) use ($pairs): void {
                foreach ($pairs as [$productId, $variantId]) {
                    $query->orWhere(function ($q) use ($productId, $variantId): void {
                        $q->where('product_id', $productId);
                        $variantId === null ? $q->whereNull('variant_id') : $q->where('variant_id', $variantId);
                    });
                }
            })
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (WarehouseProduct $wp): string => "{$wp->product_id}:{$wp->variant_id}");

        // Auto-create warehouse product records for anything never stocked at this warehouse.
        foreach ($pairs as $key => [$productId, $variantId]) {
            if (!$warehouseProducts->has($key)) {
                $warehouseProducts[$key] = WarehouseProduct::create([
                    'warehouse_id' => $so->warehouse_id,
                    'product_id'   => $productId,
                    'variant_id'   => $variantId,
                    'qty_on_hand'  => 0,
                    'qty_reserved' => 0,
                    'wac_amount'   => 0,
                ]);
            }
        }

        $shortages = [];

        foreach ($warehouseProducts as $key => $wp) {
            $available = (float) $wp->qty_on_hand - (float) $wp->qty_reserved;

            if ($available < $requested[$key] - $this->qtyTolerance()) {
                $productName = $wp->product?->name ?? "Product #{$wp->product_id}";
                $shortages[] = "{$productName}: {$available} available, {$requested[$key]} required";
            }
        }

        if ($shortages !== []) {
            throw new InsufficientStockException(
                "Cannot reserve stock for sale order #{$so->id}: " . implode('; ', $shortages),
            );
        }

        foreach ($warehouseProducts as $key => $wp) {
            $wp->increment('qty_reserved', $requested[$key]);
        }
    }

    /**
     * Fulfill sale order: decrement stock, record COGS at WAC.
     * $fulfilledQtys = [sale_order_item_id => qty] — omit to fulfill all.
     */
    public function fulfillSaleOrder(int $soId, array $fulfilledQtys = []): SaleOrder
    {
        $totalCogs = 0.0;

        $so = DB::transaction(function () use ($soId, $fulfilledQtys, &$totalCogs): SaleOrder {
            // The order row is locked and re-checked here rather than before the transaction
            // so overlapping requests for the same order (e.g. a double-click on a slow
            // connection) serialize instead of both reading a pre-fulfillment qty_fulfilled
            // and each decrementing stock / adding COGS for the same quantity.
            $so = SaleOrder::lockForUpdate()->findOrFail($soId);
            $this->assertSaleOrderAccess($so);

            // SHIPPED is a parallel courier-tracking state (see SaleOrderStatus) that doesn't
            // gate fulfilment — an order can be marked Delivered from Shipped without ever
            // having passed back through Processing/Partial.
            if (!in_array($so->status, [SaleOrderStatus::PROCESSING, SaleOrderStatus::PARTIAL, SaleOrderStatus::SHIPPED], true)) {
                throw new InvalidTransitionException("Sale order #{$soId} cannot be fulfilled from status [{$so->status->value}].");
            }

            $items = $so->items()->with('product')->lockForUpdate()->get();
            $fullyFulfilled = true;

            foreach ($items as $item) {
                // $fulfilledQtys supports two formats:
                //   legacy:  [item_id => qty]
                //   with lot: [item_id => ['qty' => x, 'lot_id' => y, 'serial_ids' => [...]]]
                $raw = $fulfilledQtys[$item->id] ?? null;
                $qty = $raw === null
                    ? ((float) $item->qty_ordered - (float) $item->qty_fulfilled)
                    : (float) (is_array($raw) ? ($raw['qty'] ?? 0) : $raw);

                $lotId = is_array($raw) ? ($raw['lot_id'] ?? null) : null;
                $serialIds = is_array($raw) ? ($raw['serial_ids'] ?? []) : [];
                $fromDamaged = (bool) ($item->from_damaged ?? (is_array($raw) ? ($raw['from_damaged'] ?? false) : false));

                if ($qty <= 0) {
                    continue;
                }

                $remainingToFulfill = max(0.0, (float) $item->qty_ordered - (float) $item->qty_fulfilled);

                if ($qty > $remainingToFulfill + $this->qtyTolerance()) {
                    throw new \InvalidArgumentException("Cannot fulfill {$qty} units for sale order item [{$item->id}]; only {$remainingToFulfill} remain open.");
                }

                $wp = WarehouseProduct::where('warehouse_id', $so->warehouse_id)
                    ->where('product_id', $item->product_id)
                    ->where('variant_id', $item->variant_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $wac = (float) $wp->wac_amount;

                if ($fromDamaged) {
                    // Fulfill from the damaged bin — does not touch qty_on_hand or qty_reserved
                    $qtyDamaged = (float) $wp->qty_damaged;

                    if ($qtyDamaged + $this->qtyTolerance() < $qty) {
                        throw new InsufficientStockException("Insufficient damaged stock for sale order item [{$item->id}]: available {$qtyDamaged}, requested {$qty}.");
                    }

                    $wp->decrement('qty_damaged', $qty);

                    $item->update([
                        'qty_fulfilled'    => (float) $item->qty_fulfilled + $qty,
                        'unit_cost_amount' => $wac,
                        'from_damaged'     => true,
                        'lot_id'           => $lotId ?? $item->lot_id,
                    ]);
                    $totalCogs += round($qty * $wac, 4);

                    if ((float) $item->qty_fulfilled + $qty < (float) $item->qty_ordered - $this->qtyTolerance()) {
                        $fullyFulfilled = false;
                    }

                    // Use qty_on_hand as before/after context since damaged bin is separate
                    $qtyBefore = (float) $wp->qty_on_hand;
                    $this->writeMovement($so->warehouse_id, $item->product_id, $item->variant_id, MovementType::SALE_FULFILLMENT, $qty, $qtyBefore, $qtyBefore, $wac, $wac, SaleOrder::class, $so->id, null, 'from damaged stock', $lotId);

                    continue;
                }

                $qtyBefore = (float) $wp->qty_on_hand;
                $reservedBefore = (float) $wp->qty_reserved;

                if ($qtyBefore + $this->qtyTolerance() < $qty) {
                    throw new InsufficientStockException("Insufficient on-hand stock for sale order item [{$item->id}]: available {$qtyBefore}, requested {$qty}.");
                }

                if ($reservedBefore + $this->qtyTolerance() < $qty) {
                    throw new InsufficientStockException("Insufficient reserved stock for sale order item [{$item->id}]: reserved {$reservedBefore}, requested {$qty}.");
                }

                // FIFO/LIFO: auto-select lot when none explicitly specified
                if ($lotId === null) {
                    $costingMethod = $item->product?->costing_method ?? 'wac';

                    if ($costingMethod === 'fifo' || $costingMethod === 'lifo') {
                        $lotQuery = Lot::where('product_id', $item->product_id)
                            ->where('warehouse_id', $so->warehouse_id)
                            ->where('qty_on_hand', '>', 0)
                            ->lockForUpdate();

                        $lotQuery = $costingMethod === 'fifo'
                            ? $lotQuery->oldest('created_at')
                            : $lotQuery->latest('created_at');

                        $autoLot = $lotQuery->first();

                        if ($autoLot !== null) {
                            $lotId = $autoLot->id;
                        }
                    }
                }

                // Lot validation: ensure the specified lot belongs to this product/warehouse
                if ($lotId !== null) {
                    $lot = Lot::where('id', $lotId)
                        ->where('product_id', $item->product_id)
                        ->where('warehouse_id', $so->warehouse_id)
                        ->lockForUpdate()
                        ->first();

                    if ($lot === null) {
                        throw new \InvalidArgumentException("Lot [{$lotId}] not found for product [{$item->product_id}] in warehouse [{$so->warehouse_id}].");
                    }

                    if ((float) $lot->qty_on_hand + $this->qtyTolerance() < $qty) {
                        throw new InsufficientStockException("Insufficient lot stock for lot [{$lotId}]: available {$lot->qty_on_hand}, requested {$qty}.");
                    }

                    $lot->decrement('qty_on_hand', $qty);
                    $wac = (float) $lot->unit_cost_amount ?: $wac;
                }

                $qtyAfter = $qtyBefore - $qty;

                $wp->update([
                    'qty_on_hand'  => $qtyAfter,
                    'qty_reserved' => $reservedBefore - $qty,
                ]);

                $item->update([
                    'qty_fulfilled'    => (float) $item->qty_fulfilled + $qty,
                    'unit_cost_amount' => $wac,
                    'lot_id'           => $lotId ?? $item->lot_id,
                ]);
                $totalCogs += round($qty * $wac, 4);

                // Mark serial numbers as sold
                if (!empty($serialIds)) {
                    SerialNumber::whereIn('id', $serialIds)
                        ->where('warehouse_id', $so->warehouse_id)
                        ->update(['status' => SerialNumber::STATUS_SOLD, 'sale_order_item_id' => $item->id]);
                }

                if ((float) $item->qty_fulfilled + $qty < (float) $item->qty_ordered - $this->qtyTolerance()) {
                    $fullyFulfilled = false;
                }

                $this->writeMovement($so->warehouse_id, $item->product_id, $item->variant_id, MovementType::SALE_FULFILLMENT, $qty, $qtyBefore, $qtyAfter, $wac, $wac, SaleOrder::class, $so->id, null, null, $lotId);
            }

            $statusUpdate = [
                'status'      => $fullyFulfilled ? SaleOrderStatus::FULFILLED : SaleOrderStatus::PARTIAL,
                'cogs_amount' => (float) $so->cogs_amount + $totalCogs,
            ];

            if ($fullyFulfilled) {
                $statusUpdate['fulfilled_at'] = now();
            }

            // If fully fulfilled with no linked accounting invoice, zero the due_amount now.
            // The InvoicePaymentObserver will set it correctly if an invoice is linked later.
            if ($fullyFulfilled && !$so->accounting_invoice_id) {
                $statusUpdate['due_amount'] = 0.0;
                $statusUpdate['paid_amount'] = (float) $so->total_amount;
            }

            $so->update($statusUpdate);

            return $so->refresh();
        });

        $this->erp()->postSaleFulfillment($so, $totalCogs);
        $this->erp()->postSaleOrderInvoice($so);

        // postSaleOrderInvoice() may have just resynced due_amount from a freshly-posted
        // invoice (mutating $so in place); re-check completion against whatever due_amount
        // ended up as — covers both the ERP-enabled path (invoice posted above, still owing)
        // and the no-invoice fallback in the transaction above (due_amount forced to 0).
        $completionAttributes = $this->erp()->saleOrderCompletionAttributes($so, (float) $so->due_amount);

        if ($completionAttributes !== []) {
            $so->forceFill($completionAttributes)->saveQuietly();
        }

        return $so;
    }

    public function cancelSaleOrder(int $soId): SaleOrder
    {
        $so = SaleOrder::with('items')->findOrFail($soId);
        $this->assertSaleOrderAccess($so);
        $this->assertTransition($so->status, SaleOrderStatus::CANCELLED, "sale order #{$soId}");

        $result = DB::transaction(function () use ($so): SaleOrder {
            if (in_array($so->status, [SaleOrderStatus::PROCESSING, SaleOrderStatus::PARTIAL], true)) {
                foreach ($so->items as $item) {
                    $reserved = (float) $item->qty_ordered - (float) $item->qty_fulfilled;

                    if ($reserved > 0) {
                        $wp = WarehouseProduct::where('warehouse_id', $so->warehouse_id)
                            ->where('product_id', $item->product_id)
                            ->where('variant_id', $item->variant_id)
                            ->lockForUpdate()
                            ->first();

                        if ($wp) {
                            $wp->update([
                                'qty_reserved' => max(0.0, (float) $wp->qty_reserved - $reserved),
                            ]);
                        }
                    }
                }
            }

            $so->update(['status' => SaleOrderStatus::CANCELLED, 'cancelled_at' => now()]);

            return $so->refresh();
        });

        $this->erp()->voidSaleOrderInvoice($result);

        return $result;
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Concerns;

use Centrex\Inventory\Enums\{MovementType, PurchaseOrderStatus, StockReceiptStatus};
use Centrex\Inventory\Exceptions\{InsufficientStockException, InvalidTransitionException};
use Centrex\Inventory\Jobs\{PostStockReceiptAccountingEntryJob, VoidStockReceiptAccountingEntryJob};
use Centrex\Inventory\Models\{Lot, PurchaseOrder, PurchaseOrderItem, SerialNumber, StockReceipt, StockReceiptItem, WarehouseProduct};
use Illuminate\Support\Facades\DB;

trait ManagesStockReceipts
{
    /**
     * Create a draft GRN for a PO.
     * $items = [['purchase_order_item_id' => x, 'qty_received' => y, 'unit_cost_local' => z (optional)], ...]
     */
    public function createStockReceipt(int $poId, array $items, array $options = []): StockReceipt
    {
        $po = PurchaseOrder::with('items.product')->findOrFail($poId);

        return DB::transaction(function () use ($po, $items, $options): StockReceipt {
            $grn = $this->createWithSequentialNumber('GRN', StockReceipt::class, 'grn_number', [
                'purchase_order_id' => $po->id,
                'warehouse_id'      => $po->warehouse_id,
                'received_at'       => $options['received_at'] ?? now(),
                'notes'             => $options['notes'] ?? null,
                'status'            => StockReceiptStatus::DRAFT,
                'created_by'        => $options['created_by'] ?? null,
            ]);

            foreach ($items as $item) {
                $poItem = PurchaseOrderItem::findOrFail($item['purchase_order_item_id']);
                $qty = (float) $item['qty_received'];
                $this->ensurePositiveQuantity($qty, 'qty_received');

                if ($poItem->purchase_order_id !== $po->id) {
                    throw new \InvalidArgumentException("Purchase order item [{$poItem->id}] does not belong to purchase order [{$po->id}].");
                }

                $pendingQty = max(0.0, (float) $poItem->qty_ordered - (float) $poItem->qty_received);

                if ($qty > $pendingQty + $this->qtyTolerance()) {
                    throw new \InvalidArgumentException("Cannot receive {$qty} units for purchase order item [{$poItem->id}]; only {$pendingQty} remain open.");
                }

                $rate = (float) $po->exchange_rate;
                $unitCostLocal = (float) ($item['unit_cost_local'] ?? $poItem->unit_price_local);
                $unitCostBdt = round($unitCostLocal * $rate, 4);

                $qtyDamaged = (float) ($item['qty_damaged'] ?? 0);
                $qtyLost = (float) ($item['qty_lost'] ?? 0);

                if ($qtyDamaged < 0 || $qtyLost < 0) {
                    throw new \InvalidArgumentException('qty_damaged and qty_lost must be zero or greater.');
                }

                if ($qtyDamaged + $qtyLost > $qty + $this->qtyTolerance()) {
                    throw new \InvalidArgumentException("qty_damaged + qty_lost cannot exceed qty_received ({$qty}) for purchase order item [{$poItem->id}].");
                }

                // Lot tracking: find-or-create Lot at draft time so lot_id is stored on the item.
                // qty_on_hand is incremented only when the GRN is posted.
                $lotId = null;

                if (!empty($item['lot_number'])) {
                    $lot = Lot::firstOrCreate(
                        [
                            'product_id'   => $poItem->product_id,
                            'variant_id'   => $poItem->variant_id,
                            'warehouse_id' => $po->warehouse_id,
                            'lot_number'   => $item['lot_number'],
                        ],
                        [
                            'purchase_order_item_id' => $poItem->id,
                            'manufactured_at'        => $item['lot_manufactured_at'] ?? null,
                            'expires_at'             => $item['lot_expires_at'] ?? null,
                            'qty_initial'            => 0,
                            'qty_on_hand'            => 0,
                            'unit_cost_amount'       => $unitCostBdt,
                            'notes'                  => $item['lot_notes'] ?? null,
                        ],
                    );
                    $lotId = $lot->id;
                }

                StockReceiptItem::create([
                    'stock_receipt_id'       => $grn->id,
                    'purchase_order_item_id' => $poItem->id,
                    'product_id'             => $poItem->product_id,
                    'variant_id'             => $poItem->variant_id,
                    'lot_id'                 => $lotId,
                    'serial_numbers'         => !empty($item['serial_numbers']) ? $item['serial_numbers'] : null,
                    'qty_received'           => $qty,
                    'qty_damaged'            => $qtyDamaged,
                    'qty_lost'               => $qtyLost,
                    'unit_cost_local'        => $unitCostLocal,
                    'unit_cost_amount'       => $unitCostBdt,
                    'exchange_rate'          => $rate,
                    'wac_before_amount'      => 0,
                    'wac_after_amount'       => 0,
                ]);
            }

            return $grn->refresh();
        });
    }

    /** Post a GRN: increment stock, recalculate WAC, write stock movements. */
    public function postStockReceipt(int $grnId): StockReceipt
    {
        $grn = DB::transaction(function () use ($grnId): StockReceipt {
            // Locked and status-checked inside the transaction so a double-click on a slow
            // connection can't post the same GRN twice — the second call blocks on this row
            // lock until the first commits, then sees status=POSTED and throws.
            $grn = StockReceipt::with('items.product', 'purchaseOrder')->lockForUpdate()->findOrFail($grnId);

            if ($grn->status !== StockReceiptStatus::DRAFT) {
                throw new InvalidTransitionException("GRN #{$grnId} is already {$grn->status->value}.");
            }

            foreach ($grn->items as $item) {
                $wp = $this->lockWarehouseProduct($grn->warehouse_id, $item->product_id, $item->variant_id);

                // Only fully usable units join qty_on_hand and the WAC pool. Damaged units move to
                // the separate damaged bin and lost units are written off — counting them here too
                // (as the previous logic did) double-books stock that isn't actually sellable.
                $qtyReceived = (float) $item->qty_received;
                $qtyDamaged = (float) $item->qty_damaged;
                $qtyLost = (float) $item->qty_lost;
                $qtyGood = max(0.0, $qtyReceived - $qtyDamaged - $qtyLost);

                $qtyBefore = (float) $wp->qty_on_hand;
                $wacBefore = (float) $wp->wac_amount;
                $qtyAfter = $qtyBefore;
                $newWac = $wacBefore;

                if ($qtyGood > 0) {
                    $newWac = $this->recalculateWac($wp, $qtyGood, (float) $item->unit_cost_amount);
                    $qtyAfter = $qtyBefore + $qtyGood;
                    $wp->update(['qty_on_hand' => $qtyAfter, 'wac_amount' => $newWac]);
                }

                $item->update(['wac_before_amount' => $wacBefore, 'wac_after_amount' => $newWac]);

                if ($item->purchase_order_item_id) {
                    PurchaseOrderItem::where('id', $item->purchase_order_item_id)
                        ->increment('qty_received', $qtyReceived);
                }

                // Lot tracking: qty_initial reflects everything physically received; qty_on_hand
                // only the sellable (good) portion, since FIFO/LIFO fulfillment draws from it directly.
                if ($item->lot_id !== null) {
                    if ($qtyGood > 0) {
                        Lot::where('id', $item->lot_id)->lockForUpdate()->first()?->increment('qty_on_hand', $qtyGood);
                    }

                    Lot::where('id', $item->lot_id)->update([
                        'qty_initial'      => DB::raw("qty_initial + {$qtyReceived}"),
                        'unit_cost_amount' => $item->unit_cost_amount,
                    ]);
                }

                // Serial number tracking: create serial records with status=available
                if (!empty($item->serial_numbers)) {
                    foreach ($item->serial_numbers as $sn) {
                        SerialNumber::create([
                            'serial_number'          => $sn,
                            'product_id'             => $item->product_id,
                            'variant_id'             => $item->variant_id,
                            'lot_id'                 => $item->lot_id,
                            'warehouse_id'           => $grn->warehouse_id,
                            'purchase_order_item_id' => $item->purchase_order_item_id,
                            'status'                 => SerialNumber::STATUS_AVAILABLE,
                        ]);
                    }
                }

                if ($qtyGood > 0) {
                    $this->writeMovement($grn->warehouse_id, $item->product_id, $item->variant_id, MovementType::PURCHASE_RECEIPT, $qtyGood, $qtyBefore, $qtyAfter, (float) $item->unit_cost_amount, $newWac, StockReceipt::class, $grn->id, null, null, $item->lot_id);
                }

                // Damaged units go into a separate bin (qty_damaged) — excluded from qty_on_hand/WAC
                // above — can later be sold at a discounted price via from_damaged sale order items.
                if ($qtyDamaged > 0) {
                    $wp->increment('qty_damaged', $qtyDamaged);
                    $this->writeMovement($grn->warehouse_id, $item->product_id, $item->variant_id, MovementType::ADJUSTMENT_OUT, $qtyDamaged, $qtyAfter, $qtyAfter, (float) $item->unit_cost_amount, $newWac, StockReceipt::class, $grn->id, null, 'damaged at receipt', $item->lot_id);
                }

                // Lost units are written off entirely — never added to on-hand or any bin.
                if ($qtyLost > 0) {
                    $this->writeMovement($grn->warehouse_id, $item->product_id, $item->variant_id, MovementType::ADJUSTMENT_OUT, $qtyLost, $qtyAfter, $qtyAfter, (float) $item->unit_cost_amount, $newWac, StockReceipt::class, $grn->id, null, 'lost at receipt', $item->lot_id);
                }
            }

            $grn->update(['status' => StockReceiptStatus::POSTED]);

            if ($grn->purchaseOrder) {
                $po = $grn->purchaseOrder->fresh(['items']);
                $po->update(['status' => $po->isFullyReceived() ? PurchaseOrderStatus::RECEIVED : PurchaseOrderStatus::PARTIAL]);
            }

            return $grn->refresh();
        });

        // Queued — see PostStockReceiptAccountingEntryJob's docblock. Posts the GRN's journal
        // entry, then keeps the PO's linked bill in sync so its grni_clearing_amount reflects
        // this newly posted GRN — otherwise Accounting::postBill() would still re-debit
        // Inventory for goods this receipt already capitalized.
        PostStockReceiptAccountingEntryJob::dispatch($grn->id);

        return $grn->refresh();
    }

    /** Void a posted GRN: write compensating movements, reverse stock. */
    public function voidStockReceipt(int $grnId): StockReceipt
    {
        $grn = StockReceipt::with('items')->findOrFail($grnId);

        if ($grn->status !== StockReceiptStatus::POSTED) {
            throw new InvalidTransitionException('Only posted GRNs can be voided.');
        }

        $grn = DB::transaction(function () use ($grn): StockReceipt {
            foreach ($grn->items as $item) {
                $wp = WarehouseProduct::where('warehouse_id', $grn->warehouse_id)
                    ->where('product_id', $item->product_id)
                    ->where('variant_id', $item->variant_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Only the good (non-damaged, non-lost) portion was ever added to qty_on_hand/WAC
                // at post time — reverse the same quantity, not the full qty_received.
                $qtyDamaged = (float) $item->qty_damaged;
                $qtyGood = max(0.0, (float) $item->qty_received - $qtyDamaged - (float) $item->qty_lost);
                $tolerance = $this->qtyTolerance();

                $qtyBefore = (float) $wp->qty_on_hand;

                if ($qtyBefore + $tolerance < $qtyGood) {
                    throw new InsufficientStockException("Cannot void GRN #{$grn->id} for product [{$item->product_id}] because only {$qtyBefore} units remain in stock.");
                }

                $qtyAfter = $qtyBefore - $qtyGood;

                // WAC can only be safely un-blended if nothing else has changed it since this
                // receipt posted (i.e. it's still the top of the cost stack) — otherwise a precise
                // retroactive reversal isn't well-defined, so leave the current WAC untouched rather
                // than guess.
                $wacUnchangedSincePost = abs((float) $wp->wac_amount - (float) $item->wac_after_amount) < 0.0001;
                $restoredWac = $wacUnchangedSincePost ? (float) $item->wac_before_amount : (float) $wp->wac_amount;

                $wp->update(['qty_on_hand' => $qtyAfter, 'wac_amount' => $restoredWac]);

                if ($qtyDamaged > 0) {
                    $damagedAvailable = (float) $wp->qty_damaged;

                    if ($damagedAvailable + $tolerance < $qtyDamaged) {
                        throw new InsufficientStockException("Cannot void GRN #{$grn->id} for product [{$item->product_id}]: {$qtyDamaged} damaged units were received but only {$damagedAvailable} remain in the damaged bin (some may already be sold).");
                    }

                    $wp->decrement('qty_damaged', $qtyDamaged);
                }

                if ($item->purchase_order_item_id) {
                    PurchaseOrderItem::where('id', $item->purchase_order_item_id)
                        ->decrement('qty_received', $item->qty_received);
                }

                // Reverse lot qty_on_hand by the same good quantity that was added at post time
                if ($item->lot_id !== null && $qtyGood > 0) {
                    Lot::where('id', $item->lot_id)->decrement('qty_on_hand', $qtyGood);
                }

                // Mark serial numbers as lost (they are being returned to supplier)
                if (!empty($item->serial_numbers)) {
                    SerialNumber::where('purchase_order_item_id', $item->purchase_order_item_id)
                        ->whereIn('serial_number', $item->serial_numbers)
                        ->update(['status' => SerialNumber::STATUS_LOST]);
                }

                $this->writeMovement($grn->warehouse_id, $item->product_id, $item->variant_id, MovementType::RETURN_TO_SUPPLIER, $qtyGood, $qtyBefore, $qtyAfter, (float) $item->unit_cost_amount, $restoredWac, StockReceipt::class, $grn->id, null, 'GRN void', $item->lot_id);
            }

            $grn->update(['status' => StockReceiptStatus::VOID]);

            return $grn->refresh();
        });

        // Queued — see VoidStockReceiptAccountingEntryJob's docblock. Reverses the GRN's journal
        // entry, then keeps the linked bill's grni_clearing_amount in sync — voiding
        // un-capitalizes these goods from Inventory, so a later bill post shouldn't clear more
        // GRNI than was actually recognized.
        VoidStockReceiptAccountingEntryJob::dispatch($grn->id);

        return $grn->refresh();
    }
}

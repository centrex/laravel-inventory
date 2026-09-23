<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Concerns;

use Centrex\Inventory\Enums\{MovementType, StockReceiptStatus};
use Centrex\Inventory\Exceptions\InvalidTransitionException;
use Centrex\Inventory\Models\{Adjustment, AdjustmentItem, WarehouseProduct};
use Illuminate\Support\Facades\DB;

trait ManagesAdjustments
{
    /**
     * Create a draft adjustment against a warehouse.
     * $items = [['product_id' => x, 'qty_actual' => y], ...]
     */
    public function createAdjustment(array $data): Adjustment
    {
        return DB::transaction(function () use ($data): Adjustment {
            $adjustment = $this->createWithSequentialNumber('ADJ', Adjustment::class, 'adjustment_number', [
                'warehouse_id' => $data['warehouse_id'],
                'reason'       => $data['reason'],
                'notes'        => $data['notes'] ?? null,
                'status'       => StockReceiptStatus::DRAFT,
                'adjusted_at'  => $data['adjusted_at'] ?? now(),
                'created_by'   => $data['created_by'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                [$productId, $variantId] = $this->resolveProductReference($item);
                $wp = $this->getOrCreateWarehouseProduct($data['warehouse_id'], $productId, $variantId);
                $qtySystem = (float) $wp->qty_on_hand;
                $qtyActual = (float) $item['qty_actual'];

                AdjustmentItem::create([
                    'adjustment_id'    => $adjustment->id,
                    'product_id'       => $productId,
                    'variant_id'       => $variantId,
                    'qty_system'       => $qtySystem,
                    'qty_actual'       => $qtyActual,
                    'qty_delta'        => round($qtyActual - $qtySystem, 4),
                    'unit_cost_amount' => (float) $wp->wac_amount,
                    'notes'            => $item['notes'] ?? null,
                ]);
            }

            return $adjustment->refresh();
        });
    }

    public function postAdjustment(int $adjustmentId): Adjustment
    {
        $adjustment = DB::transaction(function () use ($adjustmentId): Adjustment {
            // Locked and status-checked inside the transaction so a double-click on a slow
            // connection can't post the same adjustment twice.
            $adjustment = Adjustment::with('items')->lockForUpdate()->findOrFail($adjustmentId);

            if ($adjustment->status !== StockReceiptStatus::DRAFT) {
                throw new InvalidTransitionException("Adjustment #{$adjustmentId} is already {$adjustment->status->value}.");
            }

            foreach ($adjustment->items as $item) {
                if (abs((float) $item->qty_delta) < $this->qtyTolerance()) {
                    continue;
                }

                $wp = WarehouseProduct::where('warehouse_id', $adjustment->warehouse_id)
                    ->where('product_id', $item->product_id)
                    ->where('variant_id', $item->variant_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $qtyBefore = (float) $wp->qty_on_hand;
                $qtyAfter = max(0.0, $qtyBefore + (float) $item->qty_delta);
                $wp->update(['qty_on_hand' => $qtyAfter]);

                $type = (float) $item->qty_delta > 0 ? MovementType::ADJUSTMENT_IN : MovementType::ADJUSTMENT_OUT;

                $this->writeMovement($adjustment->warehouse_id, $item->product_id, $item->variant_id, $type, abs((float) $item->qty_delta), $qtyBefore, $qtyAfter, (float) $item->unit_cost_amount, (float) $wp->fresh()->wac_amount, Adjustment::class, $adjustment->id);
            }

            $adjustment->update(['status' => StockReceiptStatus::POSTED]);

            return $adjustment->refresh();
        });

        $this->erp()->postAdjustment($adjustment);

        return $adjustment;
    }
}

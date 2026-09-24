<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Concerns;

use Centrex\Inventory\Enums\{MovementType, TransferStatus};
use Centrex\Inventory\Exceptions\{InsufficientStockException, InvalidTransitionException};
use Centrex\Inventory\Models\{Product, ProductVariant, Transfer, TransferBox, TransferBoxItem, TransferItem, WarehouseProduct};
use Illuminate\Support\Facades\DB;

trait ManagesTransfers
{
    /**
     * Create a draft transfer with shipping cost allocation per kg.
     * $items = [['product_id' => x, 'qty_sent' => y], ...]
     */
    public function createTransfer(array $data): Transfer
    {
        if ($data['from_warehouse_id'] === $data['to_warehouse_id']) {
            throw new \InvalidArgumentException('Source and destination warehouse must differ.');
        }

        return DB::transaction(function () use ($data): Transfer {
            $rate = (float) ($data['shipping_rate_per_kg'] ?? config('inventory.default_shipping_rate_per_kg', 0));
            $boxes = $this->normalizeTransferBoxes($data);

            $transfer = $this->createWithSequentialNumber('TRF', Transfer::class, 'transfer_number', [
                'from_warehouse_id'    => $data['from_warehouse_id'],
                'to_warehouse_id'      => $data['to_warehouse_id'],
                'supplier_id'          => $data['supplier_id'] ?? null,
                'status'               => TransferStatus::PENDING,
                'shipping_rate_per_kg' => $rate,
                'total_weight_kg'      => 0,
                'shipping_cost_amount' => 0,
                'notes'                => $data['notes'] ?? null,
                'created_by'           => $data['created_by'] ?? null,
            ]);

            $totalWeightKg = 0.0;
            $aggregates = [];
            $createdBoxItems = [];

            foreach ($boxes as $index => $boxData) {
                $measuredWeight = round((float) ($boxData['measured_weight_kg'] ?? 0), 4);
                $isDerivedBox = (bool) ($boxData['_derived'] ?? false);

                if ($isDerivedBox) {
                    if ($measuredWeight < 0) {
                        throw new \InvalidArgumentException('measured_weight_kg must be zero or greater.');
                    }
                } else {
                    $this->ensurePositiveQuantity($measuredWeight, 'measured_weight_kg');
                }

                $box = TransferBox::create([
                    'transfer_id'        => $transfer->id,
                    'box_code'           => $boxData['box_code'] ?? 'BOX-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                    'measured_weight_kg' => $measuredWeight,
                    'notes'              => $boxData['notes'] ?? null,
                ]);

                $preparedItems = [];
                $theoreticalWeightTotal = 0.0;
                $fallbackQtyTotal = 0.0;

                foreach ($boxData['items'] as $item) {
                    [$productId, $variantId] = $this->resolveProductReference($item);
                    $product = Product::findOrFail($productId);
                    $variant = $variantId ? ProductVariant::query()->findOrFail($variantId) : null;
                    $qty = round((float) $item['qty_sent'], 4);
                    $this->ensurePositiveQuantity($qty, 'qty_sent');

                    $unitWeightKg = $variant?->weight_kg ?? $product->weight_kg;
                    $theoreticalWeight = $unitWeightKg !== null
                        ? round($qty * (float) $unitWeightKg, 4)
                        : 0.0;

                    // When no variant specified, pick the WP record with most available stock
                    // so dispatch doesn't hit a ghost 0-qty base record.
                    if ($variantId === null) {
                        $sourceWp = WarehouseProduct::query()
                            ->where('warehouse_id', $data['from_warehouse_id'])
                            ->where('product_id', $product->id)
                            ->whereRaw('(qty_on_hand - qty_reserved) > 0')
                            ->orderByRaw('(qty_on_hand - qty_reserved) DESC')
                            ->first()
                            ?? $this->getOrCreateWarehouseProduct($data['from_warehouse_id'], $product->id, null);
                        $variantId = $sourceWp->variant_id;
                    } else {
                        $sourceWp = $this->getOrCreateWarehouseProduct($data['from_warehouse_id'], $product->id, $variantId);
                    }

                    $preparedItems[] = [
                        'product'                 => $product,
                        'variant_id'              => $variantId,
                        'qty_sent'                => $qty,
                        'theoretical_weight_kg'   => $theoreticalWeight,
                        'source_unit_cost_amount' => (float) $sourceWp->wac_amount,
                        'notes'                   => $item['notes'] ?? null,
                    ];

                    $theoreticalWeightTotal += $theoreticalWeight;
                    $fallbackQtyTotal += $qty;
                }

                if ($preparedItems === []) {
                    throw new \InvalidArgumentException('Each transfer box must contain at least one product line.');
                }

                foreach ($preparedItems as $preparedItem) {
                    $basis = $theoreticalWeightTotal > 0
                        ? $preparedItem['theoretical_weight_kg']
                        : $preparedItem['qty_sent'];
                    $denominator = $theoreticalWeightTotal > 0 ? $theoreticalWeightTotal : $fallbackQtyTotal;
                    $weightRatio = $denominator > 0 ? round($basis / $denominator, 8) : 0.0;
                    $allocatedWeight = $denominator > 0
                        ? round($measuredWeight * $basis / $denominator, 4)
                        : 0.0;

                    $boxItem = TransferBoxItem::create([
                        'transfer_box_id'           => $box->id,
                        'product_id'                => $preparedItem['product']->id,
                        'variant_id'                => $preparedItem['variant_id'],
                        'qty_sent'                  => $preparedItem['qty_sent'],
                        'theoretical_weight_kg'     => $preparedItem['theoretical_weight_kg'],
                        'allocated_weight_kg'       => $allocatedWeight,
                        'weight_ratio'              => $weightRatio,
                        'source_unit_cost_amount'   => $preparedItem['source_unit_cost_amount'],
                        'shipping_allocated_amount' => 0,
                        'unit_landed_cost_amount'   => $preparedItem['source_unit_cost_amount'],
                        'notes'                     => $preparedItem['notes'],
                    ]);

                    $productId = (int) $preparedItem['product']->id;
                    $variantId = $preparedItem['variant_id'];
                    $aggregateKey = $productId . ':' . (int) ($variantId ?? 0);
                    $aggregates[$aggregateKey] ??= [
                        'product_id'              => $productId,
                        'variant_id'              => $variantId,
                        'qty_sent'                => 0.0,
                        'weight_kg_total'         => 0.0,
                        'source_cost_total'       => 0.0,
                        'unit_cost_source_amount' => 0.0,
                    ];
                    $aggregates[$aggregateKey]['qty_sent'] += $preparedItem['qty_sent'];
                    $aggregates[$aggregateKey]['weight_kg_total'] += $allocatedWeight;
                    $aggregates[$aggregateKey]['source_cost_total'] += $preparedItem['source_unit_cost_amount'] * $preparedItem['qty_sent'];
                    $aggregates[$aggregateKey]['unit_cost_source_amount'] = $preparedItem['source_unit_cost_amount'];

                    $createdBoxItems[] = [
                        'model'                   => $boxItem,
                        'qty_sent'                => $preparedItem['qty_sent'],
                        'allocated_weight_kg'     => $allocatedWeight,
                        'source_unit_cost_amount' => $preparedItem['source_unit_cost_amount'],
                    ];
                }

                $totalWeightKg += $measuredWeight;
            }

            $shippingCost = round($totalWeightKg * $rate, 4);

            // The last box item absorbs whatever's left of $shippingCost instead of its own
            // independently rounded share, so Σshipping_allocated_amount reconciles exactly to
            // $shippingCost instead of drifting off by a few hundredths from per-line rounding
            // (same fix as buildShipmentBoxesAndItems()).
            $lastBoxItemIndex = count($createdBoxItems) - 1;
            $shippingRunningTotal = 0.0;

            foreach ($createdBoxItems as $index => $boxItem) {
                $allocatedShipping = match (true) {
                    $totalWeightKg <= 0          => 0.0,
                    $index === $lastBoxItemIndex => round($shippingCost - $shippingRunningTotal, 4),
                    default                      => round(($boxItem['allocated_weight_kg'] / $totalWeightKg) * $shippingCost, 4),
                };
                $shippingRunningTotal += $allocatedShipping;
                $unitLanded = $boxItem['qty_sent'] > 0
                    ? round((($boxItem['source_unit_cost_amount'] * $boxItem['qty_sent']) + $allocatedShipping) / $boxItem['qty_sent'], 4)
                    : 0.0;

                $boxItem['model']->update([
                    'shipping_allocated_amount' => $allocatedShipping,
                    'unit_landed_cost_amount'   => $unitLanded,
                ]);
            }

            $aggregateList = array_values($aggregates);
            $lastAggregateIndex = count($aggregateList) - 1;
            $shippingRunningTotal = 0.0;

            foreach ($aggregateList as $index => $aggregate) {
                $qtySent = round((float) $aggregate['qty_sent'], 4);
                $unitCostSourceAmount = $qtySent > 0
                    ? round((float) $aggregate['source_cost_total'] / $qtySent, 4)
                    : 0.0;
                $allocatedShipping = match (true) {
                    $totalWeightKg <= 0            => 0.0,
                    $index === $lastAggregateIndex => round($shippingCost - $shippingRunningTotal, 4),
                    default                        => round(((float) $aggregate['weight_kg_total'] / $totalWeightKg) * $shippingCost, 4),
                };
                $shippingRunningTotal += $allocatedShipping;
                $unitLanded = $qtySent > 0
                    ? round(((float) $aggregate['source_cost_total'] + $allocatedShipping) / $qtySent, 4)
                    : 0.0;

                TransferItem::create([
                    'transfer_id'               => $transfer->id,
                    'product_id'                => $aggregate['product_id'],
                    'variant_id'                => $aggregate['variant_id'],
                    'qty_sent'                  => $qtySent,
                    'qty_received'              => 0,
                    'unit_cost_source_amount'   => $unitCostSourceAmount,
                    'weight_kg_total'           => round((float) $aggregate['weight_kg_total'], 4),
                    'shipping_allocated_amount' => $allocatedShipping,
                    'unit_landed_cost_amount'   => $unitLanded,
                    'wac_source_before_amount'  => $unitCostSourceAmount,
                    'wac_dest_before_amount'    => 0,
                    'wac_dest_after_amount'     => 0,
                ]);
            }

            $transfer->update(['total_weight_kg' => $totalWeightKg, 'shipping_cost_amount' => $shippingCost]);

            return $transfer->refresh();
        });
    }

    /** Dispatch transfer: decrement source stock, track qty_in_transit. */
    public function dispatchTransfer(int $transferId): Transfer
    {
        return DB::transaction(function () use ($transferId): Transfer {
            // Locked and status-checked inside the transaction so two concurrent dispatch
            // calls for the same transfer can't both pass the status check and each decrement
            // source stock / increment qty_in_transit for the same shipment — the second call
            // blocks on this row lock until the first commits, then sees status=dispatched
            // and throws instead of double-dispatching.
            $transfer = Transfer::with('items.product')->lockForUpdate()->findOrFail($transferId);
            $this->assertTransition($transfer->status, TransferStatus::DISPATCHED, "transfer #{$transferId}");

            // Lock source stock rows in a canonical (product_id, variant_id) order, not
            // line-entry order — see the matching comment in ManagesReturns.
            foreach ($transfer->items->sortBy([['product_id', 'asc'], ['variant_id', 'asc']]) as $item) {
                $wp = WarehouseProduct::where('warehouse_id', $transfer->from_warehouse_id)
                    ->where('product_id', $item->product_id)
                    ->where('variant_id', $item->variant_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $available = (float) $wp->qty_on_hand - (float) $wp->qty_reserved;

                if ($available < (float) $item->qty_sent - $this->qtyTolerance()) {
                    throw new InsufficientStockException("Insufficient stock for transfer: product [{$item->product_id}] available {$available}, needed {$item->qty_sent}.");
                }

                $qtyBefore = (float) $wp->qty_on_hand;
                $qtyAfter = $qtyBefore - (float) $item->qty_sent;

                $wp->update([
                    'qty_on_hand'    => $qtyAfter,
                    'qty_in_transit' => (float) $wp->qty_in_transit + (float) $item->qty_sent,
                ]);

                // Re-price off the source WAC as it stands right now, not the WAC captured when the
                // transfer was drafted — the two can diverge if the draft sat while GRNs posted at
                // the source, and the destination must inherit the true cost of the stock leaving.
                $precision = (int) config('inventory.wac_precision', 4);
                $currentSourceCost = round((float) $wp->wac_amount, $precision);
                $shippingPerUnit = (float) $item->qty_sent > 0
                    ? (float) $item->shipping_allocated_amount / (float) $item->qty_sent
                    : 0.0;

                $item->update([
                    'unit_cost_source_amount'  => $currentSourceCost,
                    'unit_landed_cost_amount'  => round($currentSourceCost + $shippingPerUnit, $precision),
                    'wac_source_before_amount' => $currentSourceCost,
                ]);

                $this->writeMovement($transfer->from_warehouse_id, $item->product_id, $item->variant_id, MovementType::TRANSFER_OUT, (float) $item->qty_sent, $qtyBefore, $qtyAfter, $currentSourceCost, $currentSourceCost, Transfer::class, $transfer->id);
            }

            $transfer->update(['status' => TransferStatus::DISPATCHED, 'shipped_at' => now()]);

            return $transfer->refresh();
        });
    }

    /**
     * Receive transfer at destination: update destination WAC with landed cost.
     * $receivedQtys = [transfer_item_id => qty] — omit to receive full qty_sent.
     */
    public function receiveTransfer(int $transferId, array $receivedQtys = []): Transfer
    {
        return DB::transaction(function () use ($transferId, $receivedQtys): Transfer {
            // Locked inside the transaction — see dispatchTransfer() above. This also
            // serializes concurrent *partial* receives of the same transfer: qty_received is
            // read-then-written per item, so two overlapping calls without this lock could
            // each compute the same "remaining" quantity and double-credit destination stock.
            $transfer = Transfer::with('items.product')->lockForUpdate()->findOrFail($transferId);

            if (!in_array($transfer->status, [TransferStatus::DISPATCHED, TransferStatus::PARTIAL])) {
                throw new InvalidTransitionException("Transfer #{$transferId} is not in transit.");
            }

            $fullyReceived = true;

            foreach ($transfer->items->sortBy([['product_id', 'asc'], ['variant_id', 'asc']]) as $item) {
                $remainingQty = max(0.0, (float) $item->qty_sent - (float) $item->qty_received);
                $qtyReceived = isset($receivedQtys[$item->id])
                    ? (float) $receivedQtys[$item->id]
                    : $remainingQty;

                if ($qtyReceived <= 0) {
                    continue;
                }

                if ($qtyReceived > $remainingQty + $this->qtyTolerance()) {
                    throw new \InvalidArgumentException("Cannot receive {$qtyReceived} units for transfer item [{$item->id}]; only {$remainingQty} remain in transit.");
                }

                $destWp = $this->lockWarehouseProduct($transfer->to_warehouse_id, $item->product_id, $item->variant_id);

                $destWacBefore = (float) $destWp->wac_amount;
                $newDestWac = $this->recalculateWac($destWp, $qtyReceived, (float) $item->unit_landed_cost_amount);
                $destQtyBefore = (float) $destWp->qty_on_hand;
                $destQtyAfter = $destQtyBefore + $qtyReceived;

                $destWp->update(['qty_on_hand' => $destQtyAfter, 'wac_amount' => $newDestWac]);

                WarehouseProduct::where('warehouse_id', $transfer->from_warehouse_id)
                    ->where('product_id', $item->product_id)
                    ->where('variant_id', $item->variant_id)
                    ->decrement('qty_in_transit', $qtyReceived);

                $totalReceived = (float) $item->qty_received + $qtyReceived;
                $item->update(['qty_received' => $totalReceived, 'wac_dest_before_amount' => $destWacBefore, 'wac_dest_after_amount' => $newDestWac]);

                if ($totalReceived < (float) $item->qty_sent - $this->qtyTolerance()) {
                    $fullyReceived = false;
                }

                $this->writeMovement($transfer->to_warehouse_id, $item->product_id, $item->variant_id, MovementType::TRANSFER_IN, $qtyReceived, $destQtyBefore, $destQtyAfter, (float) $item->unit_landed_cost_amount, $newDestWac, Transfer::class, $transfer->id);
            }

            $newStatus = $fullyReceived ? TransferStatus::DELIVERED : TransferStatus::PARTIAL;
            $transfer->update(['status' => $newStatus, 'received_at' => $fullyReceived ? now() : $transfer->received_at]);

            return $transfer->refresh();
        });
    }
}

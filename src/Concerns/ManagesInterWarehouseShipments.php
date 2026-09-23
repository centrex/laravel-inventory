<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Concerns;

use Centrex\Inventory\Enums\{MovementType, ShipmentStatus};
use Centrex\Inventory\Exceptions\{InsufficientStockException, InvalidTransitionException};
use Centrex\Inventory\Models\{Product, ProductVariant, Shipment, ShipmentBox, ShipmentBoxItem, ShipmentItem, StockMovement, WarehouseProduct};
use Illuminate\Support\Facades\DB;

/** Box-tracked, stock-moving inter-warehouse shipments (distinct from the customer-facing Pick-Pack-Ship flow). */
trait ManagesInterWarehouseShipments
{
    /**
     * Resolve the source WarehouseProduct for a shipment/transfer line. When no variant is
     * specified, picks whichever variant row has the most available stock (so dispatch doesn't
     * hit a ghost 0-qty base record) rather than an arbitrary/first match.
     */
    private function resolveSourceWarehouseProduct(int $warehouseId, int $productId, ?int $variantId): WarehouseProduct
    {
        if ($variantId === null) {
            return WarehouseProduct::query()
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->whereRaw('(qty_on_hand - qty_reserved) > 0')
                ->orderByRaw('(qty_on_hand - qty_reserved) DESC')
                ->first()
                ?? $this->getOrCreateWarehouseProduct($warehouseId, $productId, null);
        }

        return $this->getOrCreateWarehouseProduct($warehouseId, $productId, $variantId);
    }

    /**
     * Validate that every product+variant requested across all boxes is actually available
     * (qty_on_hand − qty_reserved) at the source warehouse, aggregated across boxes — a single
     * box's request might look fine in isolation while the shipment's total for that product
     * exceeds what's on hand. Called before any shipment/box/item rows are created so a failed
     * check never leaves a partially-built draft behind.
     *
     * @param  array<int, array{measured_weight_kg?: float, box_code?: string, notes?: string, items: array<int, array{product_id: int, variant_id?: ?int, qty_sent: float}>}>  $boxes
     */
    private function assertShipmentStockAvailability(int $warehouseId, array $boxes): void
    {
        $requested = [];
        $sourceWps = [];

        foreach ($boxes as $boxData) {
            foreach ($boxData['items'] as $item) {
                [$productId, $variantId] = $this->resolveProductReference($item);
                $sourceWp = $this->resolveSourceWarehouseProduct($warehouseId, $productId, $variantId);

                $key = (int) $sourceWp->id;
                $sourceWps[$key] ??= $sourceWp;
                $requested[$key] = ($requested[$key] ?? 0.0) + round((float) $item['qty_sent'], 4);
            }
        }

        foreach ($requested as $wpId => $qty) {
            $sourceWp = $sourceWps[$wpId];
            $available = (float) $sourceWp->qty_on_hand - (float) $sourceWp->qty_reserved;

            if ($qty > $available + $this->qtyTolerance()) {
                throw new InsufficientStockException(
                    "Insufficient stock for shipment: {$this->productLabel($sourceWp->product_id, $sourceWp->variant_id)} available {$available}, requested {$qty}.",
                );
            }
        }
    }

    public function createInterWarehouseShipment(array $data): Shipment
    {
        if ($data['from_warehouse_id'] === $data['to_warehouse_id']) {
            throw new \InvalidArgumentException('Source and destination warehouse must differ.');
        }

        return DB::transaction(function () use ($data): Shipment {
            $rate = (float) ($data['shipping_rate_per_kg'] ?? 0);
            $customsAmount = round((float) ($data['customs_amount'] ?? 0), 4);
            $handlingAmount = round((float) ($data['handling_amount'] ?? 0), 4);
            $insuranceAmount = round((float) ($data['insurance_amount'] ?? 0), 4);
            $totalExtraCharges = round($customsAmount + $handlingAmount + $insuranceAmount, 4);
            $boxes = $this->normalizeTransferBoxes($data);

            $this->assertShipmentStockAvailability($data['from_warehouse_id'], $boxes);

            $shipment = $this->createWithSequentialNumber('SHP', Shipment::class, 'shipment_number', [
                'from_warehouse_id'    => $data['from_warehouse_id'],
                'to_warehouse_id'      => $data['to_warehouse_id'],
                'supplier_id'          => $data['supplier_id'] ?? null,
                'status'               => ShipmentStatus::DRAFT,
                'shipping_rate_per_kg' => $rate,
                'total_weight_kg'      => 0,
                'shipping_cost_amount' => 0,
                'customs_amount'       => $customsAmount,
                'handling_amount'      => $handlingAmount,
                'insurance_amount'     => $insuranceAmount,
                'notes'                => $data['notes'] ?? null,
                'created_by'           => $data['created_by'] ?? null,
            ]);

            $this->buildShipmentBoxesAndItems($shipment, (int) $data['from_warehouse_id'], $boxes, $rate, $totalExtraCharges);

            return $shipment->refresh();
        });
    }

    /**
     * Edit a draft inter-warehouse shipment before it's dispatched: header fields (warehouses,
     * courier, shipping rate, customs/handling/insurance) plus the full box/item breakdown are
     * replaced wholesale and re-allocated from scratch — the same math createInterWarehouseShipment()
     * uses, just against an existing shipment instead of a new one. Only DRAFT shipments are
     * editable; nothing has moved stock yet at that point (dispatch is what decrements source
     * qty_on_hand), so there's no reservation to unwind first.
     */
    public function updateInterWarehouseShipment(int $shipmentId, array $data): Shipment
    {
        if ($data['from_warehouse_id'] === $data['to_warehouse_id']) {
            throw new \InvalidArgumentException('Source and destination warehouse must differ.');
        }

        return DB::transaction(function () use ($shipmentId, $data): Shipment {
            $shipment = Shipment::lockForUpdate()->findOrFail($shipmentId);

            if ($shipment->status !== ShipmentStatus::DRAFT) {
                throw new InvalidTransitionException("Shipment #{$shipmentId} can no longer be edited (status: {$shipment->status->value}).");
            }

            $rate = (float) ($data['shipping_rate_per_kg'] ?? 0);
            $customsAmount = round((float) ($data['customs_amount'] ?? 0), 4);
            $handlingAmount = round((float) ($data['handling_amount'] ?? 0), 4);
            $insuranceAmount = round((float) ($data['insurance_amount'] ?? 0), 4);
            $totalExtraCharges = round($customsAmount + $handlingAmount + $insuranceAmount, 4);
            $boxes = $this->normalizeTransferBoxes($data);

            $this->assertShipmentStockAvailability($data['from_warehouse_id'], $boxes);

            $shipment->update([
                'from_warehouse_id'    => $data['from_warehouse_id'],
                'to_warehouse_id'      => $data['to_warehouse_id'],
                'supplier_id'          => $data['supplier_id'] ?? null,
                'shipping_rate_per_kg' => $rate,
                'customs_amount'       => $customsAmount,
                'handling_amount'      => $handlingAmount,
                'insurance_amount'     => $insuranceAmount,
                'notes'                => $data['notes'] ?? null,
            ]);

            // Boxes cascade-delete their own items at the DB level (shipment_box_items.shipment_box_id
            // is cascadeOnDelete); the per-product aggregate on the shipment itself is a sibling, not
            // a child of boxes, so it needs its own explicit wipe before rebuilding from scratch.
            $shipment->boxes()->delete();
            $shipment->items()->delete();

            $this->buildShipmentBoxesAndItems($shipment, (int) $data['from_warehouse_id'], $boxes, $rate, $totalExtraCharges);

            return $shipment->refresh();
        });
    }

    /**
     * Shared box/item builder for createInterWarehouseShipment() and updateInterWarehouseShipment():
     * creates every ShipmentBox/ShipmentBoxItem from $boxes, allocates shipping (by weight) and
     * extra charges (by value) across them, builds the per-product ShipmentItem aggregate, and
     * writes total_weight_kg/shipping_cost_amount back onto $shipment. Assumes $shipment already
     * exists and has no boxes/items of its own yet (the caller is responsible for wiping old ones
     * first on an update).
     *
     * @param  array<int, array{measured_weight_kg?: float, box_code?: string, notes?: string, _derived?: bool, items: array<int, array{product_id: int, variant_id?: ?int, qty_sent: float, notes?: string}>}>  $boxes
     */
    private function buildShipmentBoxesAndItems(Shipment $shipment, int $fromWarehouseId, array $boxes, float $rate, float $totalExtraCharges): void
    {
        $totalWeightKg = 0.0;
        $totalCostBasis = 0.0;
        $aggregates = [];
        $createdBoxItems = [];

        foreach ($boxes as $index => $boxData) {
            $measuredWeight = round((float) ($boxData['measured_weight_kg'] ?? 0), 4);
            $isDerived = (bool) ($boxData['_derived'] ?? false);

            if ($isDerived) {
                if ($measuredWeight < 0) {
                    throw new \InvalidArgumentException('measured_weight_kg must be zero or greater.');
                }
            } else {
                $this->ensurePositiveQuantity($measuredWeight, 'measured_weight_kg');
            }

            $box = ShipmentBox::create([
                'shipment_id'        => $shipment->id,
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
                $theoreticalWeight = $unitWeightKg !== null ? round($qty * (float) $unitWeightKg, 4) : 0.0;

                $sourceWp = $this->resolveSourceWarehouseProduct($fromWarehouseId, $product->id, $variantId);
                $variantId = $sourceWp->variant_id;

                $preparedItems[] = [
                    'product'               => $product,
                    'variant_id'            => $variantId,
                    'qty_sent'              => $qty,
                    'theoretical_weight_kg' => $theoreticalWeight,
                    'source_unit_cost'      => (float) $sourceWp->wac_amount,
                    'notes'                 => $item['notes'] ?? null,
                ];

                $theoreticalWeightTotal += $theoreticalWeight;
                $fallbackQtyTotal += $qty;
            }

            if ($preparedItems === []) {
                throw new \InvalidArgumentException('Each shipment box must contain at least one product line.');
            }

            foreach ($preparedItems as $pi) {
                $basis = $theoreticalWeightTotal > 0 ? $pi['theoretical_weight_kg'] : $pi['qty_sent'];
                $denominator = $theoreticalWeightTotal > 0 ? $theoreticalWeightTotal : $fallbackQtyTotal;
                $allocatedWeight = $denominator > 0 ? round($measuredWeight * $basis / $denominator, 4) : 0.0;
                $weightRatio = $denominator > 0 ? round($basis / $denominator, 8) : 0.0;

                $costTotal = $pi['source_unit_cost'] * $pi['qty_sent'];

                $boxItem = ShipmentBoxItem::create([
                    'shipment_box_id'                => $box->id,
                    'product_id'                     => $pi['product']->id,
                    'variant_id'                     => $pi['variant_id'],
                    'qty_sent'                       => $pi['qty_sent'],
                    'theoretical_weight_kg'          => $pi['theoretical_weight_kg'],
                    'allocated_weight_kg'            => $allocatedWeight,
                    'weight_ratio'                   => $weightRatio,
                    'source_unit_cost_amount'        => $pi['source_unit_cost'],
                    'shipping_allocated_amount'      => 0,
                    'extra_charges_allocated_amount' => 0,
                    'unit_landed_cost_amount'        => $pi['source_unit_cost'],
                    'notes'                          => $pi['notes'],
                ]);

                $key = $pi['product']->id . ':' . (int) ($pi['variant_id'] ?? 0);
                $aggregates[$key] ??= ['product_id' => $pi['product']->id, 'variant_id' => $pi['variant_id'], 'qty_sent' => 0.0, 'weight_total' => 0.0, 'cost_total' => 0.0];
                $aggregates[$key]['qty_sent'] += $pi['qty_sent'];
                $aggregates[$key]['weight_total'] += $allocatedWeight;
                $aggregates[$key]['cost_total'] += $costTotal;

                $createdBoxItems[] = ['model' => $boxItem, 'qty_sent' => $pi['qty_sent'], 'allocated_weight_kg' => $allocatedWeight, 'source_unit_cost' => $pi['source_unit_cost'], 'cost_total' => $costTotal];
                $totalCostBasis += $costTotal;
            }

            $totalWeightKg += $measuredWeight;
        }

        $shippingCost = round($totalWeightKg * $rate, 4);

        // Backfill each box item's placeholder shipping/extra-charges/landed-cost values
        // (set to zero/source-cost when created, above) now that $totalWeightKg and
        // $totalCostBasis — and therefore this shipment's total shipping cost and the pool
        // of extra charges to allocate by value — are finally known across all boxes.
        // The last line absorbs whatever's left of each pool instead of its own independently
        // rounded share, so Σshipping_allocated_amount and Σextra_charges_allocated_amount
        // reconcile exactly to $shippingCost / $totalExtraCharges instead of drifting off by a
        // few hundredths from per-line rounding.
        $lastBoxItemIndex = count($createdBoxItems) - 1;
        $shippingRunningTotal = 0.0;
        $extraChargesRunningTotal = 0.0;

        foreach ($createdBoxItems as $index => $entry) {
            $boxShipping = match (true) {
                $totalWeightKg <= 0          => 0.0,
                $index === $lastBoxItemIndex => round($shippingCost - $shippingRunningTotal, 4),
                default                      => round(($entry['allocated_weight_kg'] / $totalWeightKg) * $shippingCost, 4),
            };
            $shippingRunningTotal += $boxShipping;

            $boxExtraCharges = match (true) {
                $totalCostBasis <= 0         => 0.0,
                $index === $lastBoxItemIndex => round($totalExtraCharges - $extraChargesRunningTotal, 4),
                default                      => round(($entry['cost_total'] / $totalCostBasis) * $totalExtraCharges, 4),
            };
            $extraChargesRunningTotal += $boxExtraCharges;

            $boxLanded = $entry['qty_sent'] > 0
                ? round($entry['source_unit_cost'] + (($boxShipping + $boxExtraCharges) / $entry['qty_sent']), 4)
                : $entry['source_unit_cost'];

            $entry['model']->update([
                'shipping_allocated_amount'      => $boxShipping,
                'extra_charges_allocated_amount' => $boxExtraCharges,
                'unit_landed_cost_amount'        => $boxLanded,
            ]);
        }

        $aggregateList = array_values($aggregates);
        $lastAggregateIndex = count($aggregateList) - 1;
        $shippingRunningTotal = 0.0;
        $extraChargesRunningTotal = 0.0;

        foreach ($aggregateList as $index => $aggregate) {
            $qtySent = $aggregate['qty_sent'];
            $unitCost = $qtySent > 0 ? round($aggregate['cost_total'] / $qtySent, 4) : 0.0;
            $allocatedShipping = match (true) {
                $totalWeightKg <= 0            => 0.0,
                $index === $lastAggregateIndex => round($shippingCost - $shippingRunningTotal, 4),
                default                        => round(($aggregate['weight_total'] / $totalWeightKg) * $shippingCost, 4),
            };
            $shippingRunningTotal += $allocatedShipping;
            $allocatedExtraCharges = match (true) {
                $totalCostBasis <= 0           => 0.0,
                $index === $lastAggregateIndex => round($totalExtraCharges - $extraChargesRunningTotal, 4),
                default                        => round(($aggregate['cost_total'] / $totalCostBasis) * $totalExtraCharges, 4),
            };
            $extraChargesRunningTotal += $allocatedExtraCharges;
            $unitLanded = $qtySent > 0 ? round(($aggregate['cost_total'] + $allocatedShipping + $allocatedExtraCharges) / $qtySent, 4) : 0.0;

            ShipmentItem::create([
                'shipment_id'                    => $shipment->id,
                'product_id'                     => $aggregate['product_id'],
                'variant_id'                     => $aggregate['variant_id'],
                'qty_sent'                       => $qtySent,
                'qty_received'                   => 0,
                'unit_cost_source_amount'        => $unitCost,
                'weight_kg_total'                => round($aggregate['weight_total'], 4),
                'shipping_allocated_amount'      => $allocatedShipping,
                'extra_charges_allocated_amount' => $allocatedExtraCharges,
                'unit_landed_cost_amount'        => $unitLanded,
                'wac_source_before_amount'       => $unitCost,
                'wac_dest_before_amount'         => 0,
                'wac_dest_after_amount'          => 0,
            ]);
        }

        $shipment->update(['total_weight_kg' => $totalWeightKg, 'shipping_cost_amount' => $shippingCost]);
    }

    public function dispatchInterWarehouseShipment(int $shipmentId): Shipment
    {
        $shipment = Shipment::with('items.product')->findOrFail($shipmentId);

        if ($shipment->status !== ShipmentStatus::DRAFT) {
            throw new InvalidTransitionException("Shipment #{$shipmentId} is not in draft status.");
        }

        return DB::transaction(function () use ($shipment): Shipment {
            foreach ($shipment->items as $item) {
                $wp = WarehouseProduct::where('warehouse_id', $shipment->from_warehouse_id)
                    ->where('product_id', $item->product_id)
                    ->where('variant_id', $item->variant_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $available = (float) $wp->qty_on_hand - (float) $wp->qty_reserved;

                if ($available < (float) $item->qty_sent - $this->qtyTolerance()) {
                    throw new InsufficientStockException("Insufficient stock for shipment: product [{$item->product_id}] available {$available}, needed {$item->qty_sent}.");
                }

                $qtyBefore = (float) $wp->qty_on_hand;
                $qtyAfter = $qtyBefore - (float) $item->qty_sent;

                $wp->update([
                    'qty_on_hand'    => $qtyAfter,
                    'qty_in_transit' => (float) $wp->qty_in_transit + (float) $item->qty_sent,
                ]);

                // Re-price off the source WAC as it stands right now, not the WAC captured when the
                // shipment was drafted — the two can diverge if the draft sat while GRNs posted at
                // the source, and the destination must inherit the true cost of the stock leaving.
                $precision = (int) config('inventory.wac_precision', 4);
                $currentSourceCost = round((float) $wp->wac_amount, $precision);
                $shippingPerUnit = (float) $item->qty_sent > 0
                    ? (float) $item->shipping_allocated_amount / (float) $item->qty_sent
                    : 0.0;
                // Extra charges (customs/handling/insurance) were allocated as fixed dollar
                // amounts by value at draft time — unlike shipping/source cost, they don't get
                // re-derived from a live rate, so the per-unit share stays as originally split.
                $extraChargesPerUnit = (float) $item->qty_sent > 0
                    ? (float) $item->extra_charges_allocated_amount / (float) $item->qty_sent
                    : 0.0;

                $item->update([
                    'unit_cost_source_amount'  => $currentSourceCost,
                    'unit_landed_cost_amount'  => round($currentSourceCost + $shippingPerUnit + $extraChargesPerUnit, $precision),
                    'wac_source_before_amount' => $currentSourceCost,
                ]);

                $this->writeMovement($shipment->from_warehouse_id, $item->product_id, $item->variant_id, MovementType::TRANSFER_OUT, (float) $item->qty_sent, $qtyBefore, $qtyAfter, $currentSourceCost, $currentSourceCost, Shipment::class, $shipment->id);
            }

            $shipment->update(['status' => ShipmentStatus::IN_TRANSIT, 'shipped_at' => now()]);

            return $shipment->refresh();
        });
    }

    public function receiveInterWarehouseShipment(int $shipmentId, array $receivedQtys = []): Shipment
    {
        $shipment = Shipment::with('items.product')->findOrFail($shipmentId);

        if (!in_array($shipment->status, [ShipmentStatus::IN_TRANSIT, ShipmentStatus::PARTIAL])) {
            throw new InvalidTransitionException("Shipment #{$shipmentId} is not in transit.");
        }

        return DB::transaction(function () use ($shipment, $receivedQtys): Shipment {
            $fullyReceived = true;

            foreach ($shipment->items as $item) {
                $remainingQty = max(0.0, (float) $item->qty_sent - (float) $item->qty_received);
                $qtyReceived = isset($receivedQtys[$item->id]) ? (float) $receivedQtys[$item->id] : $remainingQty;

                if ($qtyReceived <= 0) {
                    continue;
                }

                if ($qtyReceived > $remainingQty + $this->qtyTolerance()) {
                    throw new \InvalidArgumentException("Cannot receive {$qtyReceived} units for shipment item [{$item->id}]; only {$remainingQty} remain.");
                }

                $destWp = $this->lockWarehouseProduct($shipment->to_warehouse_id, $item->product_id, $item->variant_id);

                $destWacBefore = (float) $destWp->wac_amount;
                $newDestWac = $this->recalculateWac($destWp, $qtyReceived, (float) $item->unit_landed_cost_amount);
                $destQtyBefore = (float) $destWp->qty_on_hand;
                $destQtyAfter = $destQtyBefore + $qtyReceived;

                $destWp->update(['qty_on_hand' => $destQtyAfter, 'wac_amount' => $newDestWac]);

                WarehouseProduct::where('warehouse_id', $shipment->from_warehouse_id)
                    ->where('product_id', $item->product_id)
                    ->where('variant_id', $item->variant_id)
                    ->decrement('qty_in_transit', $qtyReceived);

                $totalReceived = (float) $item->qty_received + $qtyReceived;
                $item->update(['qty_received' => $totalReceived, 'wac_dest_before_amount' => $destWacBefore, 'wac_dest_after_amount' => $newDestWac]);

                if ($totalReceived < (float) $item->qty_sent - $this->qtyTolerance()) {
                    $fullyReceived = false;
                }

                $this->writeMovement($shipment->to_warehouse_id, $item->product_id, $item->variant_id, MovementType::TRANSFER_IN, $qtyReceived, $destQtyBefore, $destQtyAfter, (float) $item->unit_landed_cost_amount, $newDestWac, Shipment::class, $shipment->id);
            }

            $newStatus = $fullyReceived ? ShipmentStatus::RECEIVED : ShipmentStatus::PARTIAL;
            $shipment->update(['status' => $newStatus, 'received_at' => $fullyReceived ? now() : $shipment->received_at]);

            return $shipment->refresh();
        });
    }

    /**
     * Re-derive weight-based landed cost for an already-dispatched inter-warehouse shipment —
     * e.g. after correcting a product/variant's weight_kg that was wrong when the shipment was
     * built — and, for lines already received, re-blend the destination WAC those lines fed
     * using the corrected landed cost.
     *
     * Box `measured_weight_kg` (a physical fact, not derived from product weight) and every
     * line's `extra_charges_allocated_amount` / `unit_cost_source_amount` (value-based, not
     * weight-based) are left untouched; only the weight-derived theoretical_weight_kg /
     * allocated_weight_kg / weight_ratio / shipping_allocated_amount / unit_landed_cost_amount
     * are re-derived, using the same allocation math as buildShipmentBoxesAndItems().
     *
     * A line's destination WAC is only re-blended when the stock_movements audit trail shows
     * nothing has touched that warehouse/product/variant since this shipment's own TRANSFER_IN
     * — i.e. it's still exactly the state that receipt left behind. If anything else moved
     * qty_on_hand or wac_amount since (another receipt, a sale, an adjustment), a precise
     * retroactive blend is no longer well-defined, so that line is skipped and reported rather
     * than guessed at (the same principle voidStockReceipt() applies to WAC un-blending).
     *
     * @return array<int, array<string, mixed>> one row per shipment item summarizing what changed
     */
    public function recalculateShipmentLanding(int $shipmentId): array
    {
        $shipment = Shipment::with(['boxes.items.product', 'boxes.items.variant', 'items'])->findOrFail($shipmentId);

        if (in_array($shipment->status, [ShipmentStatus::DRAFT, ShipmentStatus::CANCELLED], true)) {
            throw new InvalidTransitionException("Shipment #{$shipmentId} must be dispatched before its landed cost can be recalculated (status: {$shipment->status->value}).");
        }

        return DB::transaction(function () use ($shipment): array {
            $precision = (int) config('inventory.wac_precision', 4);
            $totalWeightKg = (float) $shipment->total_weight_kg;
            $shippingCost = (float) $shipment->shipping_cost_amount;

            $aggregates = [];
            $createdBoxItems = [];

            foreach ($shipment->boxes as $box) {
                $measuredWeight = (float) $box->measured_weight_kg;
                $theoreticalWeightTotal = 0.0;
                $fallbackQtyTotal = 0.0;
                $prepared = [];

                foreach ($box->items as $boxItem) {
                    $unitWeightKg = $boxItem->variant?->weight_kg ?? $boxItem->product?->weight_kg;
                    $qty = (float) $boxItem->qty_sent;
                    $theoreticalWeight = $unitWeightKg !== null ? round($qty * (float) $unitWeightKg, 4) : 0.0;

                    $prepared[] = ['boxItem' => $boxItem, 'qty' => $qty, 'theoretical_weight_kg' => $theoreticalWeight];
                    $theoreticalWeightTotal += $theoreticalWeight;
                    $fallbackQtyTotal += $qty;
                }

                foreach ($prepared as $p) {
                    $basis = $theoreticalWeightTotal > 0 ? $p['theoretical_weight_kg'] : $p['qty'];
                    $denominator = $theoreticalWeightTotal > 0 ? $theoreticalWeightTotal : $fallbackQtyTotal;
                    $allocatedWeight = $denominator > 0 ? round($measuredWeight * $basis / $denominator, 4) : 0.0;
                    $weightRatio = $denominator > 0 ? round($basis / $denominator, 8) : 0.0;

                    $boxItem = $p['boxItem'];
                    $key = $boxItem->product_id . ':' . (int) ($boxItem->variant_id ?? 0);
                    $aggregates[$key] ??= [
                        'product_id'   => $boxItem->product_id, 'variant_id' => $boxItem->variant_id,
                        'weight_total' => 0.0,
                    ];
                    $aggregates[$key]['weight_total'] += $allocatedWeight;

                    $createdBoxItems[] = [
                        'model'                 => $boxItem,
                        'qty_sent'              => $p['qty'],
                        'allocated_weight_kg'   => $allocatedWeight,
                        'weight_ratio'          => $weightRatio,
                        'theoretical_weight_kg' => $p['theoretical_weight_kg'],
                    ];
                }
            }

            // Same last-line-absorbs-remainder rounding reconciliation as
            // buildShipmentBoxesAndItems(), so Σshipping_allocated_amount still reconciles
            // exactly to $shippingCost after the re-derived weight ratios shift each share.
            $lastBoxItemIndex = count($createdBoxItems) - 1;
            $shippingRunningTotal = 0.0;

            foreach ($createdBoxItems as $index => $entry) {
                $boxShipping = match (true) {
                    $totalWeightKg <= 0          => 0.0,
                    $index === $lastBoxItemIndex => round($shippingCost - $shippingRunningTotal, 4),
                    default                      => round(($entry['allocated_weight_kg'] / $totalWeightKg) * $shippingCost, 4),
                };
                $shippingRunningTotal += $boxShipping;

                $boxItem = $entry['model'];
                $extraCharges = (float) $boxItem->extra_charges_allocated_amount;
                $sourceUnitCost = (float) $boxItem->source_unit_cost_amount;
                $boxLanded = $entry['qty_sent'] > 0
                    ? round($sourceUnitCost + (($boxShipping + $extraCharges) / $entry['qty_sent']), 4)
                    : $sourceUnitCost;

                $boxItem->update([
                    'theoretical_weight_kg'     => $entry['theoretical_weight_kg'],
                    'allocated_weight_kg'       => $entry['allocated_weight_kg'],
                    'weight_ratio'              => $entry['weight_ratio'],
                    'shipping_allocated_amount' => $boxShipping,
                    'unit_landed_cost_amount'   => $boxLanded,
                ]);
            }

            $aggregateList = array_values($aggregates);
            $lastAggregateIndex = count($aggregateList) - 1;
            $shippingRunningTotal = 0.0;
            $results = [];

            foreach ($aggregateList as $index => $aggregate) {
                $item = $shipment->items->first(
                    fn (ShipmentItem $candidate): bool => $candidate->product_id === $aggregate['product_id']
                        && (int) ($candidate->variant_id ?? 0) === (int) ($aggregate['variant_id'] ?? 0),
                );

                if (!$item) {
                    continue;
                }

                $qtySent = (float) $item->qty_sent;
                $allocatedShipping = match (true) {
                    $totalWeightKg <= 0            => 0.0,
                    $index === $lastAggregateIndex => round($shippingCost - $shippingRunningTotal, 4),
                    default                        => round(($aggregate['weight_total'] / $totalWeightKg) * $shippingCost, 4),
                };
                $shippingRunningTotal += $allocatedShipping;

                $extraCharges = (float) $item->extra_charges_allocated_amount;
                $sourceCost = (float) $item->unit_cost_source_amount;
                $newLandedCost = $qtySent > 0
                    ? round($sourceCost + (($allocatedShipping + $extraCharges) / $qtySent), 4)
                    : $sourceCost;
                $oldLandedCost = (float) $item->unit_landed_cost_amount;

                $row = [
                    'product_id'      => $item->product_id,
                    'variant_id'      => $item->variant_id,
                    'old_shipping'    => (float) $item->shipping_allocated_amount,
                    'new_shipping'    => $allocatedShipping,
                    'old_landed_cost' => $oldLandedCost,
                    'new_landed_cost' => $newLandedCost,
                    'wac_corrected'   => false,
                    'old_wac'         => null,
                    'new_wac'         => null,
                    'skipped_reason'  => null,
                ];

                $item->update([
                    'weight_kg_total'           => round($aggregate['weight_total'], 4),
                    'shipping_allocated_amount' => $allocatedShipping,
                    'unit_landed_cost_amount'   => $newLandedCost,
                ]);

                $qtyReceived = (float) $item->qty_received;

                if ($qtyReceived > 0) {
                    $destWp = WarehouseProduct::query()
                        ->where('warehouse_id', $shipment->to_warehouse_id)
                        ->where('product_id', $item->product_id)
                        ->where('variant_id', $item->variant_id)
                        ->lockForUpdate()
                        ->first();

                    $lastReceiptMovement = StockMovement::query()
                        ->where('warehouse_id', $shipment->to_warehouse_id)
                        ->where('product_id', $item->product_id)
                        ->where('variant_id', $item->variant_id)
                        ->where('reference_type', Shipment::class)
                        ->where('reference_id', $shipment->id)
                        ->where('movement_type', MovementType::TRANSFER_IN)
                        ->orderByDesc('id')
                        ->first();

                    $laterMovementExists = $lastReceiptMovement !== null && StockMovement::query()
                        ->where('warehouse_id', $shipment->to_warehouse_id)
                        ->where('product_id', $item->product_id)
                        ->where('variant_id', $item->variant_id)
                        ->where('id', '>', $lastReceiptMovement->id)
                        ->exists();

                    if ($destWp === null) {
                        $row['skipped_reason'] = 'Destination warehouse-product row no longer exists.';
                    } elseif ($lastReceiptMovement === null) {
                        $row['skipped_reason'] = 'No TRANSFER_IN movement found for this line; cannot verify it is safe to correct.';
                    } elseif ($laterMovementExists) {
                        $row['skipped_reason'] = 'Other stock activity has touched this product/warehouse since it was received — skipped to avoid an ill-defined retroactive WAC blend.';
                    } else {
                        // Nothing has touched this bin since the receipt, so its current
                        // qty_on_hand is exactly (qtyBefore + qtyReceived) and its current
                        // wac_amount is exactly the blend that used $oldLandedCost — which means
                        // the corrected blend can be derived without knowing qtyBefore at all:
                        // newWac = oldWac + qtyReceived × (newLandedCost − oldLandedCost) / totalQty
                        $totalQty = (float) $destWp->qty_on_hand;
                        $oldWac = (float) $destWp->wac_amount;
                        $correctedWac = $totalQty > 0
                            ? round($oldWac + ($qtyReceived * ($newLandedCost - $oldLandedCost) / $totalQty), $precision)
                            : round($newLandedCost, $precision);

                        $row['old_wac'] = $oldWac;
                        $row['new_wac'] = $correctedWac;
                        $row['wac_corrected'] = abs($correctedWac - $oldWac) >= 0.0001;

                        $destWp->update(['wac_amount' => $correctedWac]);
                        $item->update(['wac_dest_after_amount' => $correctedWac]);

                        $this->writeMovement(
                            $shipment->to_warehouse_id,
                            $item->product_id,
                            $item->variant_id,
                            MovementType::ADJUSTMENT_IN,
                            0.0,
                            $totalQty,
                            $totalQty,
                            $newLandedCost,
                            $correctedWac,
                            Shipment::class,
                            $shipment->id,
                            null,
                            "WAC correction: shipment #{$shipment->shipment_number} landed cost recalculated after fixing product weight_kg (unit landed cost {$oldLandedCost} → {$newLandedCost}).",
                        );
                    }
                }

                $results[] = $row;
            }

            return $results;
        });
    }
}

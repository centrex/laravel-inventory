<?php

declare(strict_types = 1);

namespace Centrex\Inventory;

use Carbon\Carbon;
use Centrex\Accounting\Models\{Bill, Invoice};
use Centrex\Inventory\Enums\{MovementType, PurchaseOrderStatus, SaleOrderStatus, ShipmentStatus, StockReceiptStatus};
use Centrex\Inventory\Exceptions\{InsufficientStockException, InvalidTransitionException};
use Centrex\Inventory\Models\{Adjustment, Customer, CustomerProductStat, Lot, Product, ProductCategory, ProductTrendSnapshot, ProductVariant, ProductVariantAttributeType, ProductVariantAttributeValue, PurchaseOrder, PurchaseOrderItem, SaleOrder, SaleOrderItem, SerialNumber, Shipment, ShipmentBox, ShipmentBoxItem, ShipmentItem, StockMovement, StockReceipt, StockReceiptItem, Supplier, SupplierProductStat, Transfer, Warehouse, WarehouseProduct};
use Centrex\Inventory\Support\{CommercialTeamAccess, DayRange, InventoryEntityRegistry, SalesTargetCalculator};
use Centrex\ModelData\Models\Data;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB, Gate, Schema};

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
    use Concerns\HasSharedInventoryHelpers;
    use Concerns\ManagesAdjustments;
    use Concerns\ManagesExchangeRates;
    use Concerns\ManagesPickPackShip;
    use Concerns\ManagesPricing;
    use Concerns\ManagesPurchaseOrders;
    use Concerns\ManagesReturns;
    use Concerns\ManagesSaleOrderLifecycle;
    use Concerns\ManagesSaleOrders;
    use Concerns\ManagesStockLedger;
    use Concerns\ManagesStockReceipts;
    use Concerns\ManagesTransfers;

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
    // Inter-Warehouse Shipments (box-tracked, stock-moving)
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Reporting
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Mobile / Query helpers
    // -------------------------------------------------------------------------

    /**
     * Default active warehouse (highest priority by is_default, then name).
     */
    public function defaultWarehouse(): ?Warehouse
    {
        return Warehouse::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->first();
    }

    /**
     * List products with optional filters.
     */
    public function listProducts(
        bool $activeOnly = true,
        bool $availableOnly = false,
        ?string $search = null,
        ?int $categoryId = null,
    ): Collection {
        return Product::query()
            ->with(['category', 'brand', 'warehouseProducts', 'prices'])
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->when($availableOnly, fn ($q) => $q->whereHas('warehouseProducts', fn ($wq) => $wq->whereRaw('qty_on_hand > qty_reserved')))
            ->when($search, fn ($q) => $q->where(fn ($sq) => $sq->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->orderBy('name')
            ->get();
    }

    /**
     * Find a single product with full relations.
     */
    public function findProduct(int $id): ?Product
    {
        return Product::query()
            ->with(['category', 'brand', 'warehouseProducts', 'prices', 'variants'])
            ->find($id);
    }

    /**
     * Look up a product or variant by barcode.
     * Returns ['product' => Product, 'variant' => ProductVariant|null] or null if not found.
     */
    public function findByBarcode(string $barcode): ?array
    {
        $variant = ProductVariant::query()
            ->with(['product.category', 'product.brand', 'product.prices', 'warehouseProducts', 'attributeValues.attributeType'])
            ->where('barcode', $barcode)
            ->first();

        if ($variant !== null) {
            return ['product' => $variant->product, 'variant' => $variant];
        }

        $product = Product::query()
            ->with(['category', 'brand', 'warehouseProducts', 'prices', 'variants'])
            ->where('barcode', $barcode)
            ->first();

        return $product !== null ? ['product' => $product, 'variant' => null] : null;
    }

    // ── Lot management ────────────────────────────────────────────────────────

    /**
     * List all lots for a product/warehouse, ordered by creation date (oldest first — FIFO order).
     */
    public function getLotsByProduct(int $productId, int $warehouseId, ?int $variantId = null): Collection
    {
        return Lot::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->when($variantId !== null, fn ($q) => $q->where('variant_id', $variantId))
            ->where('qty_on_hand', '>', 0)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Find a specific lot by its number within a product/warehouse scope.
     */
    public function findLotByNumber(int $productId, int $warehouseId, string $lotNumber, ?int $variantId = null): ?Lot
    {
        return Lot::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('lot_number', $lotNumber)
            ->when($variantId !== null, fn ($q) => $q->where('variant_id', $variantId))
            ->first();
    }

    /**
     * Return all lots expiring within the given number of days.
     */
    public function getExpiringLots(int $withinDays = 30, ?int $warehouseId = null): Collection
    {
        return Lot::when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($withinDays))
            ->where('qty_on_hand', '>', 0)
            ->orderBy('expires_at')
            ->get();
    }

    /**
     * Return all available serial numbers for a product/warehouse, optionally scoped to a lot.
     */
    public function getAvailableSerialNumbers(int $productId, int $warehouseId, ?int $lotId = null): Collection
    {
        return SerialNumber::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', SerialNumber::STATUS_AVAILABLE)
            ->when($lotId !== null, fn ($q) => $q->where('lot_id', $lotId))
            ->orderBy('id')
            ->get();
    }

    // ── Variant management ────────────────────────────────────────────────────

    /**
     * List all variants for a product, optionally filtered to active-only.
     */
    public function listVariants(int $productId, bool $activeOnly = false): Collection
    {
        return ProductVariant::query()
            ->with(['attributeValues.attributeType', 'warehouseProducts'])
            ->forProduct($productId)
            ->when($activeOnly, fn ($q) => $q->active())
            ->ordered()
            ->get();
    }

    /**
     * Find a single variant with its relations.
     */
    public function findVariant(int $variantId): ?ProductVariant
    {
        return ProductVariant::query()
            ->with(['product', 'attributeValues.attributeType', 'warehouseProducts'])
            ->find($variantId);
    }

    /**
     * Create a new variant for an existing product.
     *
     * $data keys: sku, name, barcode?, weight_kg?, sort_order?, is_active?, attributes?, meta?
     *
     * Optionally pass 'attribute_values' => [[attribute_type_id, attribute_value_id], ...]
     * to populate the normalised pivot at the same time.
     */
    public function createVariant(int $productId, array $data): ProductVariant
    {
        $product = Product::query()->findOrFail($productId);

        return DB::transaction(function () use ($product, $data): ProductVariant {
            $variant = ProductVariant::create([
                'product_id' => $product->getKey(),
                'sku'        => $data['sku'],
                'name'       => $data['name'],
                'barcode'    => $data['barcode'] ?? null,
                'weight_kg'  => $data['weight_kg'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0,
                'is_active'  => $data['is_active'] ?? true,
                'attributes' => $data['attributes'] ?? null,
                'meta'       => $data['meta'] ?? null,
            ]);

            if (!empty($data['attribute_values'])) {
                $this->syncVariantAttributeValues($variant, $data['attribute_values']);
            }

            return $variant->load(['attributeValues.attributeType']);
        });
    }

    /**
     * Update an existing variant's fields.
     *
     * Passing 'attribute_values' replaces the normalised pivot entries completely.
     */
    public function updateVariant(int $variantId, array $data): ProductVariant
    {
        $variant = ProductVariant::query()->findOrFail($variantId);

        return DB::transaction(function () use ($variant, $data): ProductVariant {
            $fillable = array_intersect_key($data, array_flip([
                'sku', 'name', 'barcode', 'weight_kg', 'sort_order', 'is_active', 'attributes', 'meta',
            ]));

            $variant->fill($fillable)->save();

            if (array_key_exists('attribute_values', $data)) {
                $this->syncVariantAttributeValues($variant, $data['attribute_values']);
            }

            return $variant->load(['attributeValues.attributeType']);
        });
    }

    /**
     * Soft-delete a variant.  Blocked if the variant has any committed
     * transaction lines (purchase/sale order items or stock movements).
     */
    public function deleteVariant(int $variantId): void
    {
        $variant = ProductVariant::query()->findOrFail($variantId);

        if ($variant->hasTransactionHistory()) {
            throw new \RuntimeException(
                "Variant [{$variantId}] cannot be deleted because it has transaction history. Deactivate it instead.",
            );
        }

        DB::transaction(function () use ($variant): void {
            $variant->warehouseProducts()->delete();
            $variant->prices()->delete();
            $variant->delete();
        });
    }

    /**
     * Deactivate a variant without deleting it (safe for variants with history).
     */
    public function deactivateVariant(int $variantId): ProductVariant
    {
        $variant = ProductVariant::query()->findOrFail($variantId);
        $variant->update(['is_active' => false]);

        return $variant;
    }

    /**
     * Duplicate an existing variant under the same (or different) product.
     * The duplicate always gets a new SKU; all other fields can be overridden.
     */
    public function duplicateVariant(int $variantId, array $overrides = []): ProductVariant
    {
        $source = ProductVariant::query()
            ->with('attributeValues')
            ->findOrFail($variantId);

        $data = array_merge([
            'sku'        => $source->sku . '-copy',
            'name'       => $source->name . ' (copy)',
            'barcode'    => null,
            'weight_kg'  => $source->weight_kg,
            'sort_order' => $source->sort_order,
            'is_active'  => false,
            'attributes' => $source->attributes,
            'meta'       => $source->meta,
        ], $overrides);

        $productId = (int) ($overrides['product_id'] ?? $source->product_id);

        return $this->createVariant($productId, $data);
    }

    // ── Attribute type / value management ────────────────────────────────────

    /**
     * Upsert an attribute type (e.g. Color, Size).
     */
    public function upsertAttributeType(string $slug, string $name, int $sortOrder = 0): ProductVariantAttributeType
    {
        return ProductVariantAttributeType::updateOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'sort_order' => $sortOrder],
        );
    }

    /**
     * Upsert an attribute value (e.g. Color → Red).
     */
    public function upsertAttributeValue(int $attributeTypeId, string $value, array $extra = []): ProductVariantAttributeValue
    {
        return ProductVariantAttributeValue::updateOrCreate(
            ['attribute_type_id' => $attributeTypeId, 'value' => $value],
            array_merge(['sort_order' => 0], $extra),
        );
    }

    /**
     * Sync the normalised pivot rows for a variant.
     *
     * $rows: [[attribute_type_id => int, attribute_value_id => int], ...]
     */
    private function syncVariantAttributeValues(ProductVariant $variant, array $rows): void
    {
        $sync = [];

        foreach ($rows as $row) {
            $sync[(int) $row['attribute_value_id']] = [
                'attribute_type_id' => (int) $row['attribute_type_id'],
            ];
        }

        $variant->attributeValues()->sync($sync);
    }

    /**
     * List product categories with active product count.
     */
    public function listProductCategories(bool $activeOnly = true): Collection
    {
        return ProductCategory::query()
            ->withCount(['products' => fn ($q) => $q->where('is_active', true)])
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Find a single product category with product count.
     */
    public function findProductCategory(int $id): ?ProductCategory
    {
        return ProductCategory::query()->withCount('products')->find($id);
    }

    /**
     * List customers with optional filters.
     *
     * Eager-loads 'media' — Customer's primary_image_url is an always-appended accessor
     * (HasPrimaryImage::getPrimaryImageUrlAttribute() -> getFirstMediaUrl()) that otherwise
     * lazy-loads the media relation once per customer, turning every call into an N+1.
     */
    public function listCustomers(bool $activeOnly = false, ?string $search = null): Collection
    {
        return Customer::query()
            ->with('media')
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->when($search, fn ($q) => $q->where(fn ($sq) => $sq->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
            ->orderBy('name')
            ->get();
    }

    /**
     * Find a single customer by ID.
     */
    public function findCustomer(int $id): ?Customer
    {
        return Customer::query()->find($id);
    }

    /**
     * Find the customer linked to a morphable model (e.g. a User).
     */
    public function findCustomerForModel(string $morphClass, int $morphId): ?Customer
    {
        return Customer::query()
            ->where('modelable_type', $morphClass)
            ->where('modelable_id', $morphId)
            ->first();
    }

    /**
     * Create a customer with auto-generated code.
     */
    public function createCustomer(array $data): Customer
    {
        if (empty($data['code'])) {
            $data['code'] = InventoryEntityRegistry::autoGeneratedCode('customers');
        }

        $data += [
            'credit_limit_amount' => 0,
            'is_active'           => true,
            'currency'            => config('inventory.base_currency', 'BDT'),
        ];

        return Customer::query()->create($data);
    }

    /**
     * Update a customer by ID.
     */
    public function updateCustomer(int $id, array $data): Customer
    {
        $customer = Customer::query()->findOrFail($id);
        $customer->update($data);

        return $customer->fresh();
    }

    /**
     * Delete a customer by ID.
     */
    public function deleteCustomer(int $id): void
    {
        Customer::query()->findOrFail($id)->delete();
    }

    /**
     * List sale orders with optional date/status filters.
     */
    public function listSaleOrders(
        ?string $status = null,
        ?string $from = null,
        ?string $to = null,
        bool $excludeTerminal = false,
    ): Collection {
        return SaleOrder::query()
            ->with(['customer', 'warehouse', 'items.product'])
            ->where('document_type', 'sale')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($excludeTerminal, fn ($q) => $q->whereNotIn('status', [SaleOrderStatus::FULFILLED->value, SaleOrderStatus::COMPLETED->value, SaleOrderStatus::CANCELLED->value, SaleOrderStatus::RETURNED->value]))
            ->when($from, fn ($q) => $q->where('ordered_at', '>=', DayRange::from($from)))
            ->when($to, fn ($q) => $q->where('ordered_at', '<', DayRange::until($to)))
            ->latest('ordered_at')
            ->get();
    }

    /**
     * Find a single sale order with relations.
     */
    public function findSaleOrder(int $id): ?SaleOrder
    {
        return SaleOrder::query()
            ->with(['customer', 'warehouse', 'items.product'])
            ->where('document_type', 'sale')
            ->find($id);
    }

    /**
     * Oldest pending sale order date (draft/confirmed/processing/partial).
     */
    public function oldestPendingOrderDate(): ?string
    {
        $order = SaleOrder::query()
            ->where('document_type', 'sale')
            ->whereIn('status', [
                SaleOrderStatus::DRAFT->value,
                SaleOrderStatus::CONFIRMED->value,
                SaleOrderStatus::PROCESSING->value,
                SaleOrderStatus::PARTIAL->value,
            ])
            ->oldest('ordered_at')
            ->first();

        return $order?->ordered_at?->toDateString();
    }

    /**
     * Estimate shipping cost for a list of items (product_id + qty).
     */
    public function estimateShipping(array $items): array
    {
        $products = Product::query()
            ->whereIn('id', collect($items)->pluck('product_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $rate = (float) config('inventory.shipping_rate_per_kg', env('ERP_APP_SHIPPING_RATE_PER_KG', 120));

        $totalWeightKg = collect($items)->sum(function (array $item) use ($products): float {
            $product = $products->get((int) ($item['product_id'] ?? 0));
            $qty = (float) ($item['qty'] ?? $item['quantity'] ?? 0);

            return (float) ($product?->weight_kg ?? 0) * $qty;
        });

        return [
            'total_weight_kg' => round($totalWeightKg, 4),
            'rate_per_kg'     => $rate,
            'shipping_cost'   => $totalWeightKg > 0 ? round($totalWeightKg * $rate, 2) : 0.0,
        ];
    }

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

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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

    private function assertTransition(mixed $current, mixed $target, string $subject): void
    {
        if (!$current->canTransitionTo($target)) {
            throw new InvalidTransitionException("Cannot transition {$subject} from [{$current->value}] to [{$target->value}].");
        }
    }

    private function normalizeTransferBoxes(array $data): array
    {
        if (!empty($data['boxes'])) {
            return array_values($data['boxes']);
        }

        if (empty($data['items'])) {
            throw new \InvalidArgumentException('At least one transfer box or product line is required.');
        }

        $measuredWeight = 0.0;

        foreach ($data['items'] as $item) {
            $product = Product::findOrFail($item['product_id']);
            $qty = round((float) $item['qty_sent'], 4);
            $this->ensurePositiveQuantity($qty, 'qty_sent');
            $measuredWeight += $product->weight_kg !== null
                ? round($qty * (float) $product->weight_kg, 4)
                : 0.0;
        }

        return [[
            '_derived'           => true,
            'box_code'           => 'BOX-001',
            'measured_weight_kg' => round($measuredWeight, 4),
            'notes'              => $data['notes'] ?? null,
            'items'              => $data['items'],
        ]];
    }

    private function resolveCreditOverride(?Customer $customer, float $newOrderAmount, array $data): array
    {
        if (!$customer) {
            return [
                'credit_limit_amount'           => 0.0,
                'credit_exposure_before_amount' => 0.0,
                'credit_exposure_after_amount'  => 0.0,
                'credit_override_required'      => false,
                'credit_override_approved_by'   => null,
                'credit_override_approved_at'   => null,
                'credit_override_notes'         => null,
            ];
        }

        $creditLimit = round((float) $customer->credit_limit_amount, 4);
        $creditExposureBefore = $this->customerOutstandingExposure($customer->id);
        $creditExposureAfter = round($creditExposureBefore + $newOrderAmount, 4);
        $limitBreached = $creditLimit > 0
            ? $creditExposureAfter > $creditLimit + $this->qtyTolerance()
            : $creditExposureAfter > $this->qtyTolerance();

        if (!$limitBreached) {
            return [
                'credit_limit_amount'           => $creditLimit,
                'credit_exposure_before_amount' => $creditExposureBefore,
                'credit_exposure_after_amount'  => $creditExposureAfter,
                'credit_override_required'      => false,
                'credit_override_approved_by'   => null,
                'credit_override_approved_at'   => null,
                'credit_override_notes'         => null,
            ];
        }

        // Credit limit breached: flag the order for review rather than blocking it.
        // Approve automatically only when the submitter holds the approve-credit gate.
        $approvedBy = $data['credit_override_approved_by'] ?? $data['created_by'] ?? $this->currentUserId();
        $canApprove = $this->canApproveCreditOverride($approvedBy);

        return [
            'credit_limit_amount'           => $creditLimit,
            'credit_exposure_before_amount' => $creditExposureBefore,
            'credit_exposure_after_amount'  => $creditExposureAfter,
            'credit_override_required'      => true,
            'credit_override_approved_by'   => $canApprove ? $approvedBy : null,
            'credit_override_approved_at'   => $canApprove ? now() : null,
            'credit_override_notes'         => $data['credit_override_notes'] ?? null,
        ];
    }

    private function customerOutstandingExposure(int $customerId): float
    {
        // Open orders (confirmed but not yet delivered): always count due_amount.
        // due_amount is set at creation (= total_amount) and reduced by InvoicePaymentObserver
        // whenever a linked accounting invoice receives a payment.
        $openStatuses = [
            SaleOrderStatus::CONFIRMED->value,
            SaleOrderStatus::PROCESSING->value,
            SaleOrderStatus::PARTIAL->value,
        ];

        $openExposure = (float) SaleOrder::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', $openStatuses)
            ->sum('due_amount');

        // FULFILLED orders: only count those with a linked accounting invoice.
        // Without an invoice the goods were delivered without credit (COD/cash),
        // so there is no outstanding receivable to track.
        // due_amount on these rows is kept current by InvoicePaymentObserver.
        $fulfilledExposure = (float) SaleOrder::query()
            ->where('customer_id', $customerId)
            ->where('status', SaleOrderStatus::FULFILLED->value)
            ->whereNotNull('accounting_invoice_id')
            ->sum('due_amount');

        return round($openExposure + $fulfilledExposure, 4);
    }

    private function supplierOutstandingExposure(int $supplierId): float
    {
        // Open purchase orders (confirmed but not yet fully received): always count due_amount.
        // due_amount is set at creation (= total_amount) and reduced by BillPaymentObserver
        // whenever a linked accounting bill receives a payment.
        $openStatuses = [
            PurchaseOrderStatus::CONFIRMED->value,
            PurchaseOrderStatus::PARTIAL->value,
        ];

        $openExposure = (float) PurchaseOrder::query()
            ->where('supplier_id', $supplierId)
            ->whereIn('status', $openStatuses)
            ->sum('due_amount');

        // RECEIVED orders: only count those with a linked accounting bill.
        // Without a bill the goods were received without credit terms, so there is no
        // outstanding payable to track. due_amount on these rows is kept current by
        // BillPaymentObserver.
        $receivedExposure = (float) PurchaseOrder::query()
            ->where('supplier_id', $supplierId)
            ->where('status', PurchaseOrderStatus::RECEIVED->value)
            ->whereNotNull('accounting_bill_id')
            ->sum('due_amount');

        return round($openExposure + $receivedExposure, 4);
    }

    private function canApproveCreditOverride(?int $approvedBy): bool
    {
        if (auth()->check()) {
            return Gate::forUser(auth()->user())->allows('inventory.sale-orders.approve-credit');
        }

        return $approvedBy !== null;
    }

    private function currentUserId(): ?int
    {
        $user = auth()->user();

        if (!$user || !method_exists($user, 'getAuthIdentifier')) {
            return null;
        }

        return (int) $user->getAuthIdentifier();
    }

    private function defaultPurchaseWarehouseId(): int
    {
        $name = (string) config('inventory.purchase_defaults.warehouse_name', 'UK');

        $warehouse = Warehouse::query()->where('name', $name)->first();

        if (!$warehouse) {
            throw new \RuntimeException("Default purchase warehouse [{$name}] not found.");
        }

        return (int) $warehouse->id;
    }

    private function defaultSaleWarehouseId(): int
    {
        $name = (string) config('inventory.sale_defaults.warehouse_name', 'UK');

        $warehouse = Warehouse::query()->where('name', $name)->first();

        if (!$warehouse) {
            throw new \RuntimeException("Default sale warehouse [{$name}] not found.");
        }

        return (int) $warehouse->id;
    }

    private function salesAssignment(array $data, ?Customer $customer, ?int $createdBy): array
    {
        $assignment = CommercialTeamAccess::assignmentFor('sales', $createdBy);
        $explicit = array_filter([
            'sales_manager_id'           => $data['sales_manager_id'] ?? $customer?->sales_manager_id ?? null,
            'sales_assistant_manager_id' => $data['sales_assistant_manager_id'] ?? $customer?->sales_assistant_manager_id ?? null,
            'sales_executive_id'         => $data['sales_executive_id'] ?? $customer?->sales_executive_id ?? null,
        ], fn ($value): bool => $value !== null);

        return array_replace($assignment, $explicit);
    }

    private function purchaseAssignment(array $data, ?int $createdBy): array
    {
        $supplier = isset($data['supplier_id']) ? Supplier::query()->find((int) $data['supplier_id']) : null;
        $assignment = CommercialTeamAccess::assignmentFor('purchase', $createdBy);
        $explicit = array_filter([
            'purchase_manager_id'           => $data['purchase_manager_id'] ?? $supplier?->purchase_manager_id ?? null,
            'purchase_assistant_manager_id' => $data['purchase_assistant_manager_id'] ?? $supplier?->purchase_assistant_manager_id ?? null,
            'purchase_executive_id'         => $data['purchase_executive_id'] ?? $supplier?->purchase_executive_id ?? null,
        ], fn ($value): bool => $value !== null);

        return array_replace($assignment, $explicit);
    }

    private function customerSegment(float $revenue, int $ordersCount): string
    {
        return match (true) {
            $revenue >= 500000 || $ordersCount >= 20 => 'Strategic',
            $revenue >= 100000 || $ordersCount >= 6  => 'Growth',
            $ordersCount > 1                         => 'Repeat',
            default                                  => 'New',
        };
    }

    private function assertSaleOrderAccess(SaleOrder $saleOrder): void
    {
        $visibleUserIds = CommercialTeamAccess::visibleUserIds('sales');

        if ($visibleUserIds === null) {
            return;
        }

        $ownerIds = collect([
            $saleOrder->created_by,
            $saleOrder->sales_manager_id,
            $saleOrder->sales_assistant_manager_id,
            $saleOrder->sales_executive_id,
        ])->filter()->map(fn ($id): int => (int) $id)->all();

        abort_unless(count(array_intersect($visibleUserIds, $ownerIds)) > 0, 403);
    }

    private function assertPurchaseOrderAccess(PurchaseOrder $purchaseOrder): void
    {
        $visibleUserIds = CommercialTeamAccess::visibleUserIds('purchase');

        if ($visibleUserIds === null) {
            return;
        }

        $ownerIds = collect([
            $purchaseOrder->created_by,
            $purchaseOrder->purchase_manager_id,
            $purchaseOrder->purchase_assistant_manager_id,
            $purchaseOrder->purchase_executive_id,
        ])->filter()->map(fn ($id): int => (int) $id)->all();

        abort_unless(count(array_intersect($visibleUserIds, $ownerIds)) > 0, 403);
    }

    private function normalizeSaleDocumentType(?string $documentType): string
    {
        return $documentType === 'quotation' ? 'quotation' : 'order';
    }

    private function normalizePurchaseDocumentType(?string $documentType): string
    {
        return $documentType === 'requisition' ? 'requisition' : 'order';
    }

    private function appendConversionNote(?string $notes, string $line): string
    {
        return collect([$notes, $line])
            ->filter(fn (?string $value): bool => filled($value))
            ->implode("\n\n");
    }

    private function modelDataReady(): bool
    {
        return class_exists(Data::class)
            && Schema::hasTable('model_datas');
    }

    private function documentMetadata(Model $model): array
    {
        if (!$this->modelDataReady()) {
            return [];
        }

        $record = Data::query()
            ->forModel($model)
            ->first();

        if (!$record) {
            return [];
        }

        return is_array($record->data)
            ? $record->data
            : (json_decode((string) $record->data, true) ?: []);
    }

    private function putDocumentMetadata(Model $model, array $metadata): void
    {
        if (!$this->modelDataReady()) {
            return;
        }

        Data::putForModel($model, $metadata);
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

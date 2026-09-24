<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Concerns;

use Centrex\Inventory\Enums\SaleOrderStatus;
use Centrex\Inventory\Exceptions\InvalidTransitionException;
use Centrex\Inventory\Models\{PickList, PickListItem, SaleOrder, Shipment, ShipmentItem, WarehouseProduct};
use Illuminate\Support\Facades\DB;

trait ManagesPickPackShip
{
    /**
     * Create a pick list for a confirmed/processing sale order.
     * Each SO item becomes a pick list item pre-populated with bin location from WarehouseProduct.
     */
    public function createPickList(int $soId, array $options = []): PickList
    {
        $so = SaleOrder::with('items.product')->findOrFail($soId);

        if (!in_array($so->status, [SaleOrderStatus::CONFIRMED, SaleOrderStatus::PROCESSING, SaleOrderStatus::PARTIAL])) {
            throw new InvalidTransitionException("Cannot create pick list for sale order in status [{$so->status->value}].");
        }

        return DB::transaction(function () use ($so, $options): PickList {
            $pickList = $this->createWithSequentialNumber('PIC', PickList::class, 'pick_number', [
                'sale_order_id' => $so->id,
                'warehouse_id'  => $so->warehouse_id,
                'assigned_to'   => $options['assigned_to'] ?? null,
                'status'        => 'draft',
                'notes'         => $options['notes'] ?? null,
                'created_by'    => $this->currentUserId() ?? ($options['created_by'] ?? null),
            ]);

            foreach ($so->items as $item) {
                $remainingQty = max(0.0, (float) $item->qty_ordered - (float) $item->qty_fulfilled);

                if ($remainingQty <= 0) {
                    continue;
                }

                $wp = WarehouseProduct::where('warehouse_id', $so->warehouse_id)
                    ->where('product_id', $item->product_id)
                    ->where('variant_id', $item->variant_id)
                    ->first();

                PickListItem::create([
                    'pick_list_id'       => $pickList->id,
                    'sale_order_item_id' => $item->id,
                    'product_id'         => $item->product_id,
                    'variant_id'         => $item->variant_id,
                    'lot_id'             => $item->lot_id,
                    'bin_location'       => $wp?->bin_location,
                    'qty_to_pick'        => $remainingQty,
                    'qty_picked'         => 0,
                ]);
            }

            return $pickList->refresh();
        });
    }

    /** Mark a pick list as actively being picked (draft → picking). */
    public function startPicking(int $pickListId): PickList
    {
        return DB::transaction(function () use ($pickListId): PickList {
            $pickList = PickList::lockForUpdate()->findOrFail($pickListId);

            if ($pickList->status !== 'draft') {
                throw new InvalidTransitionException("Pick list #{$pickListId} is not in draft status.");
            }

            $pickList->update(['status' => 'picking']);

            return $pickList->refresh();
        });
    }

    /**
     * Confirm pick: record actual quantities picked.
     * $pickedQtys = [pick_list_item_id => ['qty_picked' => x, 'lot_id' => y, 'serial_numbers' => [...]]]
     * Transitions status: picking → picked
     */
    public function confirmPick(int $pickListId, array $pickedQtys): PickList
    {
        return DB::transaction(function () use ($pickListId, $pickedQtys): PickList {
            $pickList = PickList::with('items')->lockForUpdate()->findOrFail($pickListId);

            if ($pickList->status !== 'picking') {
                throw new InvalidTransitionException("Pick list #{$pickListId} must be in 'picking' status to confirm.");
            }

            foreach ($pickList->items as $item) {
                $data = $pickedQtys[$item->id] ?? null;

                if ($data === null) {
                    continue;
                }

                $qtyPicked = (float) (is_array($data) ? ($data['qty_picked'] ?? 0) : $data);
                $lotId = is_array($data) ? ($data['lot_id'] ?? null) : null;
                $serials = is_array($data) ? ($data['serial_numbers'] ?? []) : [];

                $item->update([
                    'qty_picked'     => $qtyPicked,
                    'lot_id'         => $lotId ?? $item->lot_id,
                    'serial_numbers' => !empty($serials) ? $serials : $item->serial_numbers,
                ]);
            }

            $pickList->update([
                'status'    => 'picked',
                'picked_at' => now(),
            ]);

            return $pickList->refresh();
        });
    }

    /**
     * Create a shipment for a sale order. Links to pick list items for lot/serial traceability.
     * $data['items'] = [['sale_order_item_id' => x, 'qty_shipped' => y, 'lot_id' => z], ...]
     */
    public function createShipment(int $soId, array $data): Shipment
    {
        $so = SaleOrder::with('items')->findOrFail($soId);

        if (!in_array($so->status, [SaleOrderStatus::PROCESSING, SaleOrderStatus::PARTIAL])) {
            throw new InvalidTransitionException("Cannot create shipment for sale order in status [{$so->status->value}].");
        }

        return DB::transaction(function () use ($so, $data): Shipment {
            $shipment = $this->createWithSequentialNumber('SHP', Shipment::class, 'shipment_number', [
                'sale_order_id'         => $so->id,
                'warehouse_id'          => $so->warehouse_id,
                'carrier'               => $data['carrier'] ?? null,
                'tracking_number'       => $data['tracking_number'] ?? null,
                'status'                => 'pending',
                'notes'                 => $data['notes'] ?? null,
                'estimated_delivery_at' => $data['estimated_delivery_at'] ?? null,
                'created_by'            => $this->currentUserId() ?? ($data['created_by'] ?? null),
            ]);

            $itemsMap = $so->items->keyBy('id');

            foreach ($data['items'] ?? [] as $itemData) {
                $soItem = $itemsMap[$itemData['sale_order_item_id']] ?? null;

                if ($soItem === null) {
                    continue;
                }

                ShipmentItem::create([
                    'shipment_id'        => $shipment->id,
                    'sale_order_item_id' => $soItem->id,
                    'product_id'         => $soItem->product_id,
                    'variant_id'         => $soItem->variant_id,
                    'lot_id'             => $itemData['lot_id'] ?? $soItem->lot_id,
                    'qty_shipped'        => (float) $itemData['qty_shipped'],
                ]);
            }

            return $shipment->refresh();
        });
    }

    /**
     * Dispatch a shipment: triggers stock fulfillment and marks shipment as dispatched.
     * This is where qty_on_hand is decremented and COGS is recorded.
     */
    public function dispatchShipment(int $shipmentId): Shipment
    {
        return DB::transaction(function () use ($shipmentId): Shipment {
            // Locked and status-checked inside the transaction. fulfillSaleOrder() opens its
            // own DB::transaction(), which nests as a savepoint under this one, so the
            // shipment-level lock stays held for its full duration — without it, two racing
            // dispatch calls for the same shipment could both pass the 'pending' check and
            // both call fulfillSaleOrder(); the first succeeds, and if the second then throws
            // (fulfillSaleOrder's own guard rejects the now-already-fulfilled quantity), that
            // exception would previously propagate before this method's own status update ran,
            // leaving the shipment stuck at 'pending' despite stock already having moved.
            $shipment = Shipment::with('items')->lockForUpdate()->findOrFail($shipmentId);

            if ($shipment->status !== 'pending') {
                throw new InvalidTransitionException("Shipment #{$shipmentId} is already {$shipment->status}.");
            }

            // Build fulfilledQtys from shipment items for fulfillSaleOrder
            $fulfilledQtys = $shipment->items
                ->mapWithKeys(fn (ShipmentItem $item) => [
                    $item->sale_order_item_id => [
                        'qty'    => (float) $item->qty_shipped,
                        'lot_id' => $item->lot_id,
                    ],
                ])
                ->all();

            $this->fulfillSaleOrder((int) $shipment->sale_order_id, $fulfilledQtys);

            $shipment->update([
                'status'        => 'dispatched',
                'dispatched_at' => now(),
            ]);

            return $shipment->refresh();
        });
    }

    /** Mark a shipment as delivered. */
    public function markShipmentDelivered(int $shipmentId): Shipment
    {
        return DB::transaction(function () use ($shipmentId): Shipment {
            $shipment = Shipment::lockForUpdate()->findOrFail($shipmentId);

            if ($shipment->status !== 'dispatched') {
                throw new InvalidTransitionException("Shipment #{$shipmentId} must be dispatched before marking delivered.");
            }

            $shipment->update(['status' => 'delivered']);

            return $shipment->refresh();
        });
    }
}

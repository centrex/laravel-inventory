<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Concerns;

use Centrex\Inventory\Enums\MovementType;
use Centrex\Inventory\Exceptions\{InsufficientStockException, InvalidTransitionException};
use Centrex\Inventory\Models\{PurchaseOrder, PurchaseReturn, PurchaseReturnItem, SaleOrder, SaleOrderItem, SaleReturn, SaleReturnItem};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Customer returns (against a sale order) and supplier returns (against a purchase order). */
trait ManagesReturns
{
    public function createSaleReturn(array $data): SaleReturn
    {
        return DB::transaction(function () use ($data): SaleReturn {
            $saleOrder = isset($data['sale_order_id']) ? SaleOrder::query()->with('items')->find($data['sale_order_id']) : null;
            $requestedQuantities = collect($data['items'])
                ->groupBy(fn (array $item): string => (int) $item['product_id'] . ':' . (int) ($item['variant_id'] ?? 0))
                ->map(fn (Collection $items): float => round((float) $items->sum('qty_returned'), 4))
                ->all();

            if ($saleOrder && isset($data['customer_id']) && (int) $data['customer_id'] !== (int) $saleOrder->customer_id) {
                throw ValidationException::withMessages([
                    'customer_id' => 'Customer is determined by the selected sale order.',
                ]);
            }

            $saleReturn = $this->createWithSequentialNumber('SRT', SaleReturn::class, 'return_number', [
                'sale_order_id' => $saleOrder?->getKey(),
                'warehouse_id'  => $data['warehouse_id'],
                'customer_id'   => $saleOrder?->customer_id ?? ($data['customer_id'] ?? null),
                'status'        => 'draft',
                'returned_at'   => $data['returned_at'] ?? now(),
                'notes'         => $data['notes'] ?? null,
                'created_by'    => $data['created_by'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                [$productId, $variantId] = $this->resolveProductReference($item);
                $qty = round((float) $item['qty_returned'], 4);
                $this->ensurePositiveQuantity($qty, 'qty_returned');
                $stock = $this->getOrCreateWarehouseProduct($data['warehouse_id'], $productId, $variantId);
                $saleOrderItem = $saleOrder?->items->first(fn ($orderItem) => (int) $orderItem->product_id === $productId && (int) ($orderItem->variant_id ?? 0) === (int) ($variantId ?? 0));

                if ($saleOrder) {
                    if (!$saleOrderItem) {
                        throw ValidationException::withMessages([
                            'items' => ["Product [{$productId}] is not available on the selected sale order."],
                        ]);
                    }

                    $alreadyReturned = (float) SaleReturnItem::query()
                        ->where('sale_order_item_id', $saleOrderItem->getKey())
                        ->sum('qty_returned');
                    $baseQty = (float) $saleOrderItem->qty_fulfilled > 0
                        ? (float) $saleOrderItem->qty_fulfilled
                        : (float) $saleOrderItem->qty_ordered;
                    $maxReturnable = max(0.0, round($baseQty - $alreadyReturned, 4));

                    $referenceKey = $productId . ':' . (int) ($variantId ?? 0);

                    if (($requestedQuantities[$referenceKey] ?? 0.0) > $maxReturnable + $this->qtyTolerance()) {
                        throw ValidationException::withMessages([
                            'items' => ["Return quantity for product [{$productId}] exceeds the fulfilled quantity still available to return."],
                        ]);
                    }
                }

                // Default to the actually-invoiced per-unit price (line_total_amount / qty_ordered),
                // not the pre-discount SaleOrderItem::unit_price_amount — otherwise returning a
                // discounted line overcredits the customer (see issueSaleReturnCreditMemo(), which
                // sums qty_returned * this field), driving the invoice balance and SaleOrder::due_amount
                // below what's actually still owed.
                $orderedQty = (float) ($saleOrderItem?->qty_ordered ?? 0);
                $defaultUnitPrice = $saleOrderItem && $orderedQty > 0.0
                    ? round((float) $saleOrderItem->line_total_amount / $orderedQty, 4)
                    : round((float) ($saleOrderItem?->unit_price_amount ?? 0), 4);

                $unitPrice = isset($item['unit_price_amount'])
                    ? round((float) $item['unit_price_amount'], 4)
                    : $defaultUnitPrice;
                $unitCost = isset($item['unit_cost_amount'])
                    ? round((float) $item['unit_cost_amount'], 4)
                    : round((float) $stock->wac_amount, 4);

                SaleReturnItem::create([
                    'sale_return_id'     => $saleReturn->id,
                    'sale_order_item_id' => $saleOrderItem?->getKey(),
                    'product_id'         => $productId,
                    'variant_id'         => $variantId,
                    'qty_returned'       => $qty,
                    'unit_price_amount'  => $unitPrice,
                    'unit_cost_amount'   => $unitCost,
                    'line_total_amount'  => round($qty * $unitPrice, 4),
                    'notes'              => $item['notes'] ?? null,
                ]);
            }

            return $saleReturn->fresh(['items.product', 'customer', 'warehouse', 'saleOrder']);
        });
    }

    public function postSaleReturn(int $saleReturnId): SaleReturn
    {
        $saleReturn = SaleReturn::query()->with('items')->findOrFail($saleReturnId);

        if ($saleReturn->status !== 'draft') {
            throw new InvalidTransitionException("Sale return #{$saleReturn->return_number} is already {$saleReturn->status}.");
        }

        $saleReturn = DB::transaction(function () use ($saleReturn): SaleReturn {
            foreach ($saleReturn->items as $item) {
                $warehouseProduct = $this->lockWarehouseProduct($saleReturn->warehouse_id, $item->product_id, $item->variant_id);
                $qty = (float) $item->qty_returned;
                $qtyBefore = (float) $warehouseProduct->qty_on_hand;
                $qtyAfter = $qtyBefore + $qty;
                $newWac = $this->recalculateWac($warehouseProduct, $qty, (float) $item->unit_cost_amount);

                $warehouseProduct->update([
                    'qty_on_hand' => $qtyAfter,
                    'wac_amount'  => $newWac,
                ]);

                $this->writeMovement(
                    $saleReturn->warehouse_id,
                    $item->product_id,
                    $item->variant_id,
                    MovementType::CUSTOMER_RETURN,
                    $qty,
                    $qtyBefore,
                    $qtyAfter,
                    (float) $item->unit_cost_amount,
                    $newWac,
                    SaleReturn::class,
                    $saleReturn->id,
                    $saleReturn->created_by,
                    'Customer return posted',
                );
            }

            $saleReturn->update(['status' => 'posted']);

            return $saleReturn->fresh(['items.product', 'customer', 'warehouse', 'saleOrder']);
        });

        $this->erp()->postSaleReturn($saleReturn);

        return $saleReturn;
    }

    public function createPurchaseReturn(array $data): PurchaseReturn
    {
        return DB::transaction(function () use ($data): PurchaseReturn {
            $purchaseOrder = isset($data['purchase_order_id']) ? PurchaseOrder::query()->with('items')->find($data['purchase_order_id']) : null;
            $requestedQuantities = collect($data['items'])
                ->groupBy(fn (array $item): string => (int) $item['product_id'] . ':' . (int) ($item['variant_id'] ?? 0))
                ->map(fn (Collection $items): float => round((float) $items->sum('qty_returned'), 4))
                ->all();

            if ($purchaseOrder && isset($data['supplier_id']) && (int) $data['supplier_id'] !== (int) $purchaseOrder->supplier_id) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'Supplier is determined by the selected purchase order.',
                ]);
            }

            $purchaseReturn = $this->createWithSequentialNumber('PRT', PurchaseReturn::class, 'return_number', [
                'purchase_order_id' => $purchaseOrder?->getKey(),
                'warehouse_id'      => $data['warehouse_id'],
                'supplier_id'       => $purchaseOrder?->supplier_id ?? ($data['supplier_id'] ?? null),
                'status'            => 'draft',
                'returned_at'       => $data['returned_at'] ?? now(),
                'notes'             => $data['notes'] ?? null,
                'created_by'        => $data['created_by'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                [$productId, $variantId] = $this->resolveProductReference($item);
                $qty = round((float) $item['qty_returned'], 4);
                $this->ensurePositiveQuantity($qty, 'qty_returned');
                $stock = $this->getOrCreateWarehouseProduct($data['warehouse_id'], $productId, $variantId);
                $purchaseOrderItem = $purchaseOrder?->items->first(fn ($orderItem) => (int) $orderItem->product_id === $productId && (int) ($orderItem->variant_id ?? 0) === (int) ($variantId ?? 0));

                if ($purchaseOrder) {
                    if (!$purchaseOrderItem) {
                        throw ValidationException::withMessages([
                            'items' => ["Product [{$productId}] is not available on the selected purchase order."],
                        ]);
                    }

                    $alreadyReturned = (float) PurchaseReturnItem::query()
                        ->where('purchase_order_item_id', $purchaseOrderItem->getKey())
                        ->sum('qty_returned');
                    $maxReturnable = max(0.0, round((float) $purchaseOrderItem->qty_received - $alreadyReturned, 4));

                    $referenceKey = $productId . ':' . (int) ($variantId ?? 0);

                    if (($requestedQuantities[$referenceKey] ?? 0.0) > $maxReturnable + $this->qtyTolerance()) {
                        throw ValidationException::withMessages([
                            'items' => ["Return quantity for product [{$productId}] exceeds the received quantity still available to return."],
                        ]);
                    }
                }

                $unitCost = isset($item['unit_cost_amount'])
                    ? round((float) $item['unit_cost_amount'], 4)
                    : round((float) ($purchaseOrderItem?->unit_price_amount ?? $stock->wac_amount), 4);

                PurchaseReturnItem::create([
                    'purchase_return_id'     => $purchaseReturn->id,
                    'purchase_order_item_id' => $purchaseOrderItem?->getKey(),
                    'product_id'             => $productId,
                    'variant_id'             => $variantId,
                    'qty_returned'           => $qty,
                    'unit_cost_amount'       => $unitCost,
                    'line_total_amount'      => round($qty * $unitCost, 4),
                    'notes'                  => $item['notes'] ?? null,
                ]);
            }

            return $purchaseReturn->fresh(['items.product', 'supplier', 'warehouse', 'purchaseOrder']);
        });
    }

    public function postPurchaseReturn(int $purchaseReturnId): PurchaseReturn
    {
        $purchaseReturn = PurchaseReturn::query()->with('items')->findOrFail($purchaseReturnId);

        if ($purchaseReturn->status !== 'draft') {
            throw new InvalidTransitionException("Purchase return #{$purchaseReturn->return_number} is already {$purchaseReturn->status}.");
        }

        $purchaseReturn = DB::transaction(function () use ($purchaseReturn): PurchaseReturn {
            foreach ($purchaseReturn->items as $item) {
                $warehouseProduct = $this->lockWarehouseProduct($purchaseReturn->warehouse_id, $item->product_id, $item->variant_id);
                $qty = (float) $item->qty_returned;
                $qtyBefore = (float) $warehouseProduct->qty_on_hand;

                if ($qtyBefore + $this->qtyTolerance() < $qty) {
                    throw new InsufficientStockException("Insufficient stock to return product [{$item->product_id}] to supplier.");
                }

                $qtyAfter = $qtyBefore - $qty;
                $warehouseProduct->update(['qty_on_hand' => $qtyAfter]);

                $this->writeMovement(
                    $purchaseReturn->warehouse_id,
                    $item->product_id,
                    $item->variant_id,
                    MovementType::RETURN_TO_SUPPLIER,
                    $qty,
                    $qtyBefore,
                    $qtyAfter,
                    (float) $item->unit_cost_amount,
                    (float) $warehouseProduct->fresh()->wac_amount,
                    PurchaseReturn::class,
                    $purchaseReturn->id,
                    $purchaseReturn->created_by,
                    'Supplier return posted',
                );
            }

            $purchaseReturn->update(['status' => 'posted']);

            return $purchaseReturn->fresh(['items.product', 'supplier', 'warehouse', 'purchaseOrder']);
        });

        $this->erp()->postPurchaseReturn($purchaseReturn);

        return $purchaseReturn;
    }
}

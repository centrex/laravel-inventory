<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Concerns;

use Centrex\Inventory\Enums\PurchaseOrderStatus;
use Centrex\Inventory\Exceptions\InvalidTransitionException;
use Centrex\Inventory\Jobs\SyncPurchaseOrderAccountingDocumentJob;
use Centrex\Inventory\Models\{PurchaseOrder, PurchaseOrderItem};
use Illuminate\Support\Facades\DB;

trait ManagesPurchaseOrders
{
    public function createPurchaseOrder(array $data): PurchaseOrder
    {
        // See the matching comment in createSaleOrder(): resolved before the transaction opens
        // so a live exchange-rate API call can't hold the DB transaction (and its sequential
        // number lock) open for the duration of an external HTTP round-trip.
        $currency = strtoupper($data['currency'] ?? config('inventory.purchase_defaults.currency', 'GBP'));
        $rate = (float) ($data['exchange_rate'] ?? $this->getExchangeRate($currency));

        $po = DB::transaction(function () use ($data, $currency, $rate): PurchaseOrder {
            $documentType = $this->normalizePurchaseDocumentType($data['document_type'] ?? null);

            $warehouseId = $data['warehouse_id'] ?? $this->defaultPurchaseWarehouseId();

            $taxLocal = (float) ($data['tax_local'] ?? 0);
            $shippingLocal = (float) ($data['shipping_local'] ?? 0);
            $discountLocal = (float) ($data['discount_local'] ?? 0);

            $createdBy = $this->currentUserId() ?? ($data['created_by'] ?? null);
            $assignment = $this->purchaseAssignment($data, $createdBy);

            $po = $this->createWithSequentialNumber($documentType === 'requisition' ? 'REQ' : 'PO', PurchaseOrder::class, 'po_number', [
                'document_type' => $documentType,
                'warehouse_id'  => $warehouseId,
                'supplier_id'   => $data['supplier_id'],
                'currency'      => $currency,
                'exchange_rate' => $rate,
                'status'        => PurchaseOrderStatus::DRAFT,
                'ordered_at'    => $data['ordered_at'] ?? null,
                'expected_at'   => $data['expected_at'] ?? null,
                'notes'         => $data['notes'] ?? null,
                'created_by'    => $createdBy,
                ...$assignment,
                'tax_local'            => $taxLocal,
                'tax_amount'           => round($taxLocal * $rate, 4),
                'discount_local'       => $discountLocal,
                'discount_amount'      => round($discountLocal * $rate, 4),
                'shipping_local'       => $shippingLocal,
                'shipping_amount'      => round($shippingLocal * $rate, 4),
                'other_charges_amount' => (float) ($data['other_charges_amount'] ?? 0),
                'subtotal_local'       => 0,
                'subtotal_amount'      => 0,
                'total_local'          => 0,
                'total_amount'         => 0,
            ]);

            $subtotalLocal = 0.0;

            foreach ($data['items'] as $item) {
                [$productId, $variantId] = $this->resolveProductReference($item);
                $unitPriceLocal = (float) $item['unit_price_local'];
                $qty = (float) $item['qty_ordered'];
                $unitPriceBdt = round($unitPriceLocal * $rate, 4);
                $lineTotalLocal = round($qty * $unitPriceLocal, 4);
                $lineTotalBdt = round($qty * $unitPriceBdt, 4);
                $subtotalLocal += $lineTotalLocal;

                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id'        => $productId,
                    'variant_id'        => $variantId,
                    'qty_ordered'       => $qty,
                    'qty_received'      => 0,
                    'unit_price_local'  => $unitPriceLocal,
                    'unit_price_amount' => $unitPriceBdt,
                    'line_total_local'  => $lineTotalLocal,
                    'line_total_amount' => $lineTotalBdt,
                    'notes'             => $item['notes'] ?? null,
                ]);
            }

            $subtotalBdt = round($subtotalLocal * $rate, 4);
            $totalLocal = $subtotalLocal + (float) $po->tax_local - (float) $po->discount_local + (float) $po->shipping_local;
            $totalBdt = $subtotalBdt + (float) $po->tax_amount - (float) $po->discount_amount + (float) $po->shipping_amount + (float) $po->other_charges_amount;

            $po->update([
                'subtotal_local' => $subtotalLocal, 'subtotal_amount' => $subtotalBdt,
                'total_local'    => $totalLocal, 'total_amount' => $totalBdt,
                'due_amount'     => round($totalBdt, 4),
            ]);

            return $po->fresh(['supplier', 'items.product']);
        });

        // Queued — see SyncSaleOrderAccountingDocumentJob's docblock for why this can't run
        // inline right after the transaction above. refresh() picks up whatever the job has
        // already written under a synchronous queue driver (e.g. in tests); under a real async
        // queue it's a no-op until the job runs, which is the correct eventually-consistent
        // shape now that the sync no longer blocks this method.
        SyncPurchaseOrderAccountingDocumentJob::dispatch($po->id);

        return $po->refresh();
    }

    public function createPurchaseOrderFromRequisition(int $requisitionId, array $overrides = []): PurchaseOrder
    {
        $requisition = PurchaseOrder::query()
            ->with(['items'])
            ->where('document_type', 'requisition')
            ->findOrFail($requisitionId);

        if ($requisition->status === PurchaseOrderStatus::CANCELLED) {
            throw new InvalidTransitionException("Requisition #{$requisition->po_number} has been cancelled and cannot be converted.");
        }

        $metadata = $this->documentMetadata($requisition);
        $convertedPurchaseOrderId = (int) ($metadata['converted_purchase_order_id'] ?? 0);

        if ($convertedPurchaseOrderId > 0) {
            $existingPurchaseOrder = PurchaseOrder::query()
                ->where('document_type', 'order')
                ->find($convertedPurchaseOrderId);

            if ($existingPurchaseOrder) {
                return $existingPurchaseOrder->fresh(['supplier', 'items.product']);
            }
        }

        $purchaseOrder = $this->createPurchaseOrder([
            'warehouse_id'         => (int) ($overrides['warehouse_id'] ?? $requisition->warehouse_id),
            'supplier_id'          => (int) ($overrides['supplier_id'] ?? $requisition->supplier_id),
            'currency'             => (string) ($overrides['currency'] ?? $requisition->currency),
            'exchange_rate'        => (float) ($overrides['exchange_rate'] ?? $requisition->exchange_rate),
            'document_type'        => 'order',
            'ordered_at'           => $overrides['ordered_at'] ?? now(),
            'expected_at'          => $overrides['expected_at'] ?? $requisition->expected_at,
            'notes'                => $overrides['notes'] ?? $this->appendConversionNote($requisition->notes, "Converted from requisition {$requisition->po_number}."),
            'created_by'           => $overrides['created_by'] ?? $requisition->created_by,
            'tax_local'            => (float) ($overrides['tax_local'] ?? $requisition->tax_local),
            'discount_local'       => (float) ($overrides['discount_local'] ?? $requisition->discount_local),
            'shipping_local'       => (float) ($overrides['shipping_local'] ?? $requisition->shipping_local),
            'other_charges_amount' => (float) ($overrides['other_charges_amount'] ?? $requisition->other_charges_amount),
            'items'                => collect($overrides['items'] ?? $requisition->items)
                ->map(function ($item): array {
                    return [
                        'product_id'       => (int) $item['product_id'],
                        'variant_id'       => $item['variant_id'] !== null ? (int) $item['variant_id'] : null,
                        'qty_ordered'      => (float) $item['qty_ordered'],
                        'unit_price_local' => (float) $item['unit_price_local'],
                        'notes'            => $item['notes'] ?? null,
                    ];
                })
                ->values()
                ->all(),
        ]);

        $this->putDocumentMetadata($requisition, array_merge($metadata, [
            'converted_purchase_order_id'     => $purchaseOrder->getKey(),
            'converted_purchase_order_number' => $purchaseOrder->po_number,
            'converted_purchase_order_at'     => now()->toIso8601String(),
        ]));

        $this->putDocumentMetadata($purchaseOrder, array_merge($this->documentMetadata($purchaseOrder), [
            'source_requisition_id'     => $requisition->getKey(),
            'source_requisition_number' => $requisition->po_number,
        ]));

        return $purchaseOrder;
    }

    /** Transition a PO from DRAFT → SUBMITTED (sets ordered_at if not already set). */
    public function submitPurchaseOrder(int $poId): PurchaseOrder
    {
        $po = PurchaseOrder::findOrFail($poId);
        $this->assertPurchaseOrderAccess($po);
        $this->assertTransition($po->status, PurchaseOrderStatus::SUBMITTED, "purchase order #{$poId}");
        $po->update(['status' => PurchaseOrderStatus::SUBMITTED, 'ordered_at' => $po->ordered_at ?? now()]);

        return $po;
    }

    /** Transition a PO from SUBMITTED → CONFIRMED and sync the document to accounting. */
    public function confirmPurchaseOrder(int $poId): PurchaseOrder
    {
        $po = PurchaseOrder::findOrFail($poId);
        $this->assertPurchaseOrderAccess($po);
        $this->assertTransition($po->status, PurchaseOrderStatus::CONFIRMED, "purchase order #{$poId}");
        $po->update(['status' => PurchaseOrderStatus::CONFIRMED]);
        SyncPurchaseOrderAccountingDocumentJob::dispatch($po->id);

        return $po->refresh();
    }

    public function receivePurchaseOrder(int $poId, array $receivedQtys = [], array $options = []): PurchaseOrder
    {
        $po = PurchaseOrder::with('items')->findOrFail($poId);
        $this->assertPurchaseOrderAccess($po);

        if (!in_array($po->status, [PurchaseOrderStatus::CONFIRMED, PurchaseOrderStatus::PARTIAL], true)) {
            throw new InvalidTransitionException("Purchase order #{$poId} cannot be received from status [{$po->status->value}].");
        }

        $items = [];

        foreach ($po->items as $item) {
            $remainingQty = max(0.0, (float) $item->qty_ordered - (float) $item->qty_received);

            if ($remainingQty <= $this->qtyTolerance()) {
                continue;
            }

            $qty = array_key_exists($item->id, $receivedQtys)
                ? (float) $receivedQtys[$item->id]
                : $remainingQty;

            if ($qty <= 0) {
                continue;
            }

            $items[] = [
                'purchase_order_item_id' => $item->id,
                'qty_received'           => $qty,
                'unit_cost_local'        => (float) $item->unit_price_local,
            ];
        }

        if ($items === []) {
            throw new \InvalidArgumentException("Purchase order #{$poId} has no remaining quantity to receive.");
        }

        $grn = $this->createStockReceipt($poId, $items, $options);
        $this->postStockReceipt((int) $grn->getKey());

        return PurchaseOrder::query()->with(['items.product', 'supplier', 'warehouse'])->findOrFail($poId);
    }

    /** Transition a PO from DRAFT|SUBMITTED → CANCELLED. */
    public function cancelPurchaseOrder(int $poId): PurchaseOrder
    {
        $po = PurchaseOrder::findOrFail($poId);
        $this->assertPurchaseOrderAccess($po);
        $this->assertTransition($po->status, PurchaseOrderStatus::CANCELLED, "purchase order #{$poId}");
        $po->update(['status' => PurchaseOrderStatus::CANCELLED]);

        return $po;
    }
}

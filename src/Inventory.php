<?php

declare(strict_types = 1);

namespace Centrex\Inventory;

use Carbon\Carbon;
use Centrex\Inventory\Enums\{MovementType, PriceTierCode, PurchaseOrderStatus, SaleOrderStatus, StockReceiptStatus, TransferStatus};
use Centrex\Inventory\Exceptions\{InsufficientStockException, InvalidTransitionException, PriceNotFoundException};
use Centrex\Inventory\Models\{Adjustment, AdjustmentItem, Coupon, Customer, Lot, PickList, PickListItem, Product, ProductCategory, ProductPrice, ProductVariant, ProductVariantAttributeType, ProductVariantAttributeValue, PurchaseOrder, PurchaseOrderItem, PurchaseReturn, PurchaseReturnItem, SaleOrder, SaleOrderItem, SaleReturn, SaleReturnItem, SerialNumber, Shipment, ShipmentItem, StockMovement, StockReceipt, StockReceiptItem, Supplier, Transfer, TransferBox, TransferBoxItem, TransferItem, Warehouse, WarehouseProduct};
use Centrex\Inventory\Support\{CommercialTeamAccess, ErpIntegration};
use Centrex\LaravelOpenExchangeRates\Models\ExchangeRate as OpenExchangeRate;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class Inventory
{
    // -------------------------------------------------------------------------
    // Exchange Rates
    // -------------------------------------------------------------------------

    public function setExchangeRate(string $currency, float $rate, ?string $date = null, string $source = 'manual'): OpenExchangeRate
    {
        $baseCurrency = strtoupper(config('inventory.base_currency', 'BDT'));
        $currency = strtoupper($currency);
        $fetchedAt = Carbon::parse($date ?? now()->toDateString())->endOfDay();

        OpenExchangeRate::upsertRates([
            $currency => $rate,
        ], $baseCurrency, $fetchedAt);

        return OpenExchangeRate::query()
            ->where('base', $baseCurrency)
            ->where('currency', $currency)
            ->where('fetched_at', '<=', $fetchedAt->toDateTimeString())
            ->latest('fetched_at')
            ->firstOrFail();
    }

    public function getExchangeRate(string $currency, ?string $date = null): float
    {
        $currency = strtoupper($currency);

        if ($currency === strtoupper(config('inventory.base_currency', 'BDT'))) {
            return 1.0;
        }

        $date ??= now()->toDateString();
        $asOf = Carbon::parse($date)->endOfDay();

        $rate = $this->lookupExchangeRate($baseCurrency = strtoupper(config('inventory.base_currency', 'BDT')), $currency, $asOf);

        if ($rate !== null) {
            return $rate;
        }

        $anchorCurrency = strtoupper(config('laravel-open-exchange-rates.default_base_currency', 'USD'));

        $sourceToBase = $this->lookupExchangeRate($currency, $baseCurrency, $asOf);

        if ($sourceToBase !== null) {
            return $sourceToBase;
        }

        $anchorToBase = $this->lookupExchangeRate($anchorCurrency, $baseCurrency, $asOf);

        if ($currency === $anchorCurrency && $anchorToBase !== null) {
            return $anchorToBase;
        }

        $anchorToCurrency = $this->lookupExchangeRate($anchorCurrency, $currency, $asOf);

        if ($anchorToBase !== null && $anchorToCurrency !== null && $anchorToCurrency != 0.0) {
            return round($anchorToBase / $anchorToCurrency, 8);
        }

        throw new \RuntimeException("No exchange rate found for currency [{$currency}] on or before [{$date}].");
    }

    public function convertToBase(float $amount, string $currency, ?string $date = null): float
    {
        return round($amount * $this->getExchangeRate($currency, $date), (int) config('inventory.wac_precision', 4));
    }

    public function convertToBdt(float $amount, string $currency, ?string $date = null): float
    {
        return $this->convertToBase($amount, $currency, $date);
    }

    public function convertFromBase(float $amount, string $currency, ?string $date = null): float
    {
        $rate = $this->getExchangeRate($currency, $date);

        if ($rate == 0.0) {
            return 0.0;
        }

        return round($amount / $rate, (int) config('inventory.wac_precision', 4));
    }

    public function convertFromBdt(float $amountBdt, string $currency, ?string $date = null): float
    {
        return $this->convertFromBase($amountBdt, $currency, $date);
    }

    private function lookupExchangeRate(string $base, string $currency, Carbon $asOf): ?float
    {
        $row = OpenExchangeRate::query()
            ->where('base', strtoupper($base))
            ->where('currency', strtoupper($currency))
            ->where('fetched_at', '<=', $asOf->toDateTimeString())
            ->orderByDesc('fetched_at')
            ->first();

        return $row ? (float) $row->rate : null;
    }

    // -------------------------------------------------------------------------
    // Price Tiers
    // -------------------------------------------------------------------------

    public function seedPriceTiers(): void
    {
        // Price tiers are enum-backed and no longer persisted in a dedicated table.
    }

    // -------------------------------------------------------------------------
    // Price Management
    // -------------------------------------------------------------------------

    /**
     * Set or update a sell price for a product + tier (optionally scoped to a warehouse).
     * warehouse_id = null means global/default.
     */
    public function setPrice(int $productId, string $tierCode, float $priceAmount, ?int $warehouseId = null, array $options = []): ProductPrice
    {
        $tierCode = $this->normalizePriceTierCode($tierCode);
        $variantId = $this->normalizeVariantId($options['variant_id'] ?? null, $productId);
        $isDamaged = (bool) ($options['is_damaged'] ?? false);

        $data = [
            'variant_id'      => $variantId,
            'price_tier_code' => $tierCode,
            'price_amount'    => $priceAmount,
            'cost_price'      => $options['cost_price'] ?? null,
            'moq'             => $options['moq'] ?? 1,
            'price_local'     => $options['price_local'] ?? null,
            'currency'        => $options['currency'] ?? null,
            'effective_from'  => $options['effective_from'] ?? null,
            'effective_to'    => $options['effective_to'] ?? null,
            'is_active'       => $options['is_active'] ?? true,
            'is_damaged'      => $isDamaged,
        ];

        return ProductPrice::updateOrCreate(
            [
                'product_id'      => $productId,
                'variant_id'      => $variantId,
                'price_tier_code' => $tierCode,
                'warehouse_id'    => $warehouseId,
                'effective_from'  => $data['effective_from'],
                'is_damaged'      => $isDamaged,
            ],
            $data,
        );
    }

    /**
     * Resolve the effective sell price for a product + tier at a given warehouse.
     * Priority: warehouse-specific active price → global active price.
     * Pass $damaged=true to prefer damaged-condition prices; falls back to regular price if none found.
     */
    public function resolvePrice(int $productId, string $tierCode, int $warehouseId, ?string $date = null, ?int $variantId = null, bool $damaged = false): ProductPrice
    {
        $tierCode = $this->normalizePriceTierCode($tierCode);
        $date ??= now()->toDateString();
        $variantId = $this->normalizeVariantId($variantId, $productId);

        $makeBase = fn (bool $isDamaged) => ProductPrice::where('product_id', $productId)
            ->where('price_tier_code', $tierCode)
            ->where('is_active', true)
            ->where('is_damaged', $isDamaged)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));

        $lookup = function (bool $isDamaged) use ($makeBase, $variantId, $warehouseId): ?ProductPrice {
            $base = $makeBase($isDamaged);
            $price = null;

            if ($variantId !== null) {
                $price = (clone $base)->where('variant_id', $variantId)->where('warehouse_id', $warehouseId)->latest()->first();
                $price ??= (clone $base)->where('variant_id', $variantId)->whereNull('warehouse_id')->latest()->first();
            }

            $price ??= (clone $base)->whereNull('variant_id')->where('warehouse_id', $warehouseId)->latest()->first();
            $price ??= (clone $base)->whereNull('variant_id')->whereNull('warehouse_id')->latest()->first();

            return $price;
        };

        $price = $damaged ? ($lookup(true) ?? $lookup(false)) : $lookup(false);

        if (!$price) {
            if (config('inventory.price_not_found_throws', true)) {
                throw new PriceNotFoundException("No price found for product [{$productId}], tier [{$tierCode}], warehouse [{$warehouseId}].");
            }

            return new ProductPrice(['price_amount' => 0, 'price_local' => 0]);
        }

        return $price;
    }

    /**
     * Get all tier prices for a product at a warehouse (global fallback per tier).
     */
    public function getPriceSheet(int $productId, int $warehouseId, ?string $date = null, ?int $variantId = null): Collection
    {
        return collect(PriceTierCode::ordered())
            ->map(function (PriceTierCode $tier) use ($productId, $warehouseId, $date, $variantId) {
                try {
                    $price = $this->resolvePrice($productId, $tier->value, $warehouseId, $date, $variantId);
                } catch (PriceNotFoundException) {
                    $price = null;
                }

                return [
                    'tier_code'    => $tier->value,
                    'tier_name'    => $tier->label(),
                    'price_amount' => $price?->price_amount,
                    'price_local'  => $price?->price_local,
                    'currency'     => $price?->currency,
                    'source'       => $price ? ($price->warehouse_id ? 'warehouse' : 'global') : null,
                ];
            });
    }

    // -------------------------------------------------------------------------
    // Warehouse-Product (Stock Ledger)
    // -------------------------------------------------------------------------

    public function getOrCreateWarehouseProduct(int $warehouseId, int $productId, ?int $variantId = null): WarehouseProduct
    {
        $variantId = $this->normalizeVariantId($variantId, $productId);

        return WarehouseProduct::firstOrCreate(
            ['warehouse_id' => $warehouseId, 'product_id' => $productId, 'variant_id' => $variantId],
            ['qty_on_hand' => 0, 'qty_reserved' => 0, 'qty_in_transit' => 0, 'wac_amount' => 0],
        );
    }

    public function getStockLevel(int $productId, int $warehouseId, ?int $variantId = null): WarehouseProduct
    {
        return $this->getOrCreateWarehouseProduct($warehouseId, $productId, $variantId);
    }

    public function getStockLevels(int $warehouseId): Collection
    {
        return WarehouseProduct::with('product')->where('warehouse_id', $warehouseId)->get();
    }

    public function getLowStockItems(?int $warehouseId = null): Collection
    {
        return WarehouseProduct::with(['product', 'warehouse'])
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->whereNotNull('reorder_point')
            ->whereRaw('(qty_on_hand - qty_reserved) <= reorder_point')
            ->get();
    }

    public function getStockValue(?int $warehouseId = null): float
    {
        return (float) (WarehouseProduct::when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->selectRaw('SUM(qty_on_hand * wac_amount) as total_value')
            ->value('total_value') ?? 0.0);
    }

    // -------------------------------------------------------------------------
    // WAC Engine (internal)
    // -------------------------------------------------------------------------

    private function recalculateWac(WarehouseProduct $wp, float $qtyIn, float $unitCostAmount): float
    {
        $currentQty = (float) $wp->qty_on_hand;
        $currentWac = (float) $wp->wac_amount;

        if ($currentQty <= 0) {
            return round($unitCostAmount, (int) config('inventory.wac_precision', 4));
        }

        $newWac = (($currentQty * $currentWac) + ($qtyIn * $unitCostAmount)) / ($currentQty + $qtyIn);

        return round($newWac, (int) config('inventory.wac_precision', 4));
    }

    private function writeMovement(int $warehouseId, int $productId, ?int $variantId, MovementType $type, float $qty, float $qtyBefore, float $qtyAfter, ?float $unitCostAmount, ?float $wacAmount, ?string $refType, ?int $refId, ?int $createdBy = null, ?string $notes = null, ?int $lotId = null): StockMovement
    {
        return StockMovement::create([
            'warehouse_id'     => $warehouseId,
            'product_id'       => $productId,
            'variant_id'       => $variantId,
            'lot_id'           => $lotId,
            'movement_type'    => $type,
            'direction'        => $type->direction(),
            'qty'              => $qty,
            'qty_before'       => $qtyBefore,
            'qty_after'        => $qtyAfter,
            'unit_cost_amount' => $unitCostAmount,
            'wac_amount'       => $wacAmount,
            'reference_type'   => $refType,
            'reference_id'     => $refId,
            'notes'            => $notes,
            'moved_at'         => now(),
            'created_by'       => $createdBy,
        ]);
    }

    private function nextNumber(string $prefix, string $model, string $column): string
    {
        $today = now()->format('Ymd');
        $latest = $model::query()
            ->where($column, 'like', "{$prefix}-{$today}-%")
            ->orderByDesc($column)
            ->value($column);

        $count = $latest
            ? ((int) substr((string) $latest, -4)) + 1
            : 1;

        return "{$prefix}-{$today}-" . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }

    private function lockWarehouseProduct(int $warehouseId, int $productId, ?int $variantId = null): WarehouseProduct
    {
        $model = new WarehouseProduct();
        $variantId = $this->normalizeVariantId($variantId, $productId);

        $existing = WarehouseProduct::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return $existing;
        }

        DB::connection($model->getConnectionName())->table($model->getTable())->insertOrIgnore([
            'warehouse_id'   => $warehouseId,
            'product_id'     => $productId,
            'variant_id'     => $variantId,
            'qty_on_hand'    => 0,
            'qty_reserved'   => 0,
            'qty_in_transit' => 0,
            'wac_amount'     => 0,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return WarehouseProduct::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function normalizeVariantId(null|int|string $variantId, int $productId): ?int
    {
        if ($variantId === null || $variantId === '') {
            return null;
        }

        $variant = ProductVariant::query()->findOrFail((int) $variantId);

        if ((int) $variant->product_id !== $productId) {
            throw new \InvalidArgumentException("Variant [{$variant->getKey()}] does not belong to product [{$productId}].");
        }

        return (int) $variant->getKey();
    }

    private function resolveProductReference(array $item): array
    {
        $productId = (int) $item['product_id'];
        $variantId = $this->normalizeVariantId($item['variant_id'] ?? null, $productId);

        return [$productId, $variantId];
    }

    private function productLabel(int $productId, ?int $variantId = null): string
    {
        $product = Product::query()->find($productId);

        if ($variantId === null) {
            return $product?->display_name ?? ('#' . $productId);
        }

        $variant = ProductVariant::query()->find($variantId);

        return $variant?->display_name ?? (($product?->display_name ?? ('#' . $productId)) . ' / #' . $variantId);
    }

    private function ensurePositiveQuantity(float $qty, string $field): void
    {
        if ($qty <= 0) {
            throw new \InvalidArgumentException("{$field} must be greater than zero.");
        }
    }

    private function erp(): ErpIntegration
    {
        return app(ErpIntegration::class);
    }

    // -------------------------------------------------------------------------
    // Purchase Orders
    // -------------------------------------------------------------------------

    public function createPurchaseOrder(array $data): PurchaseOrder
    {
        $po = DB::transaction(function () use ($data): PurchaseOrder {
            $currency = strtoupper($data['currency'] ?? config('inventory.purchase_defaults.currency', 'GBP'));
            $rate = (float) ($data['exchange_rate'] ?? $this->getExchangeRate($currency));
            $documentType = $this->normalizePurchaseDocumentType($data['document_type'] ?? null);

            $warehouseId = $data['warehouse_id'] ?? $this->defaultPurchaseWarehouseId();

            $taxLocal = (float) ($data['tax_local'] ?? 0);
            $shippingLocal = (float) ($data['shipping_local'] ?? 0);
            $discountLocal = (float) ($data['discount_local'] ?? 0);

            $createdBy = $this->currentUserId() ?? ($data['created_by'] ?? null);
            $assignment = $this->purchaseAssignment($data, $createdBy);

            $po = PurchaseOrder::create([
                'po_number'     => $this->nextNumber($documentType === 'requisition' ? 'REQ' : 'PO', PurchaseOrder::class, 'po_number'),
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

            $po->update(['subtotal_local' => $subtotalLocal, 'subtotal_amount' => $subtotalBdt, 'total_local' => $totalLocal, 'total_amount' => $totalBdt]);

            return $po->fresh(['supplier', 'items.product']);
        });

        $this->erp()->syncPurchaseOrderDocument($po);

        return $po;
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

    public function submitPurchaseOrder(int $poId): PurchaseOrder
    {
        $po = PurchaseOrder::findOrFail($poId);
        $this->assertPurchaseOrderAccess($po);
        $this->assertTransition($po->status, PurchaseOrderStatus::SUBMITTED, "purchase order #{$poId}");
        $po->update(['status' => PurchaseOrderStatus::SUBMITTED, 'ordered_at' => $po->ordered_at ?? now()]);

        return $po;
    }

    public function confirmPurchaseOrder(int $poId): PurchaseOrder
    {
        $po = PurchaseOrder::findOrFail($poId);
        $this->assertPurchaseOrderAccess($po);
        $this->assertTransition($po->status, PurchaseOrderStatus::CONFIRMED, "purchase order #{$poId}");
        $po->update(['status' => PurchaseOrderStatus::CONFIRMED]);
        $this->erp()->syncPurchaseOrderDocument($po->fresh(['supplier', 'items.product']));

        return $po;
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

            if ($remainingQty <= (float) config('inventory.qty_tolerance', 0.0001)) {
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

    public function cancelPurchaseOrder(int $poId): PurchaseOrder
    {
        $po = PurchaseOrder::findOrFail($poId);
        $this->assertPurchaseOrderAccess($po);
        $this->assertTransition($po->status, PurchaseOrderStatus::CANCELLED, "purchase order #{$poId}");
        $po->update(['status' => PurchaseOrderStatus::CANCELLED]);

        return $po;
    }

    // -------------------------------------------------------------------------
    // Stock Receipts (GRN)
    // -------------------------------------------------------------------------

    /**
     * Create a draft GRN for a PO.
     * $items = [['purchase_order_item_id' => x, 'qty_received' => y, 'unit_cost_local' => z (optional)], ...]
     */
    public function createStockReceipt(int $poId, array $items, array $options = []): StockReceipt
    {
        $po = PurchaseOrder::with('items.product')->findOrFail($poId);

        return DB::transaction(function () use ($po, $items, $options): StockReceipt {
            $grn = StockReceipt::create([
                'grn_number'        => $this->nextNumber('GRN', StockReceipt::class, 'grn_number'),
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

                if ($qty > $pendingQty + (float) config('inventory.qty_tolerance', 0.0001)) {
                    throw new \InvalidArgumentException("Cannot receive {$qty} units for purchase order item [{$poItem->id}]; only {$pendingQty} remain open.");
                }

                $rate = (float) $po->exchange_rate;
                $unitCostLocal = (float) ($item['unit_cost_local'] ?? $poItem->unit_price_local);
                $unitCostBdt = round($unitCostLocal * $rate, 4);

                // Lot tracking: find-or-create Lot at draft time so lot_id is stored on the item.
                // qty_on_hand is incremented only when the GRN is posted.
                $lotId = null;

                if (!empty($item['lot_number'])) {
                    $lot = Lot::firstOrCreate(
                        [
                            'product_id'  => $poItem->product_id,
                            'variant_id'  => $poItem->variant_id,
                            'warehouse_id' => $po->warehouse_id,
                            'lot_number'  => $item['lot_number'],
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
        $grn = StockReceipt::with('items.product', 'purchaseOrder')->findOrFail($grnId);

        if ($grn->status !== StockReceiptStatus::DRAFT) {
            throw new InvalidTransitionException("GRN #{$grnId} is already {$grn->status->value}.");
        }

        $grn = DB::transaction(function () use ($grn): StockReceipt {
            foreach ($grn->items as $item) {
                $wp = $this->lockWarehouseProduct($grn->warehouse_id, $item->product_id, $item->variant_id);

                $qtyBefore = (float) $wp->qty_on_hand;
                $wacBefore = (float) $wp->wac_amount;
                $newWac = $this->recalculateWac($wp, (float) $item->qty_received, (float) $item->unit_cost_amount);
                $qtyAfter = $qtyBefore + (float) $item->qty_received;

                $wp->update(['qty_on_hand' => $qtyAfter, 'wac_amount' => $newWac]);
                $item->update(['wac_before_amount' => $wacBefore, 'wac_after_amount' => $newWac]);

                if ($item->purchase_order_item_id) {
                    PurchaseOrderItem::where('id', $item->purchase_order_item_id)
                        ->increment('qty_received', $item->qty_received);
                }

                // Lot tracking: increment lot qty_on_hand and update cost
                if ($item->lot_id !== null) {
                    Lot::where('id', $item->lot_id)->lockForUpdate()->first()?->increment('qty_on_hand', $item->qty_received);
                    Lot::where('id', $item->lot_id)->update([
                        'qty_initial'      => \Illuminate\Support\Facades\DB::raw("qty_initial + {$item->qty_received}"),
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

                $this->writeMovement($grn->warehouse_id, $item->product_id, $item->variant_id, MovementType::PURCHASE_RECEIPT, (float) $item->qty_received, $qtyBefore, $qtyAfter, (float) $item->unit_cost_amount, $newWac, StockReceipt::class, $grn->id, null, null, $item->lot_id);

                // Damaged units go into a separate bin (qty_damaged) — can be sold at discounted price.
                // Lost units are written off entirely. Neither affects qty_on_hand.
                if ((float) $item->qty_damaged > 0) {
                    $wp->increment('qty_damaged', $item->qty_damaged);
                    $this->writeMovement($grn->warehouse_id, $item->product_id, $item->variant_id, MovementType::ADJUSTMENT_OUT, (float) $item->qty_damaged, $qtyAfter, $qtyAfter, (float) $item->unit_cost_amount, $newWac, StockReceipt::class, $grn->id, null, 'damaged at receipt', $item->lot_id);
                }

                if ((float) $item->qty_lost > 0) {
                    $this->writeMovement($grn->warehouse_id, $item->product_id, $item->variant_id, MovementType::ADJUSTMENT_OUT, (float) $item->qty_lost, $qtyAfter, $qtyAfter, (float) $item->unit_cost_amount, $newWac, StockReceipt::class, $grn->id, null, 'lost at receipt', $item->lot_id);
                }
            }

            $grn->update(['status' => StockReceiptStatus::POSTED]);

            if ($grn->purchaseOrder) {
                $po = $grn->purchaseOrder->fresh(['items']);
                $po->update(['status' => $po->isFullyReceived() ? PurchaseOrderStatus::RECEIVED : PurchaseOrderStatus::PARTIAL]);
            }

            return $grn->refresh();
        });

        $this->erp()->postStockReceipt($grn);

        return $grn;
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

                $qtyBefore = (float) $wp->qty_on_hand;
                $qtyDelta = (float) $item->qty_received;

                if ($qtyBefore + (float) config('inventory.qty_tolerance', 0.0001) < $qtyDelta) {
                    throw new InsufficientStockException("Cannot void GRN #{$grnId} for product [{$item->product_id}] because only {$qtyBefore} units remain in stock.");
                }

                $qtyAfter = $qtyBefore - $qtyDelta;
                $wp->update(['qty_on_hand' => $qtyAfter]);

                if ($item->purchase_order_item_id) {
                    PurchaseOrderItem::where('id', $item->purchase_order_item_id)
                        ->decrement('qty_received', $item->qty_received);
                }

                // Reverse lot qty_on_hand
                if ($item->lot_id !== null) {
                    Lot::where('id', $item->lot_id)->decrement('qty_on_hand', $item->qty_received);
                }

                // Mark serial numbers as lost (they are being returned to supplier)
                if (!empty($item->serial_numbers)) {
                    SerialNumber::where('purchase_order_item_id', $item->purchase_order_item_id)
                        ->whereIn('serial_number', $item->serial_numbers)
                        ->update(['status' => SerialNumber::STATUS_LOST]);
                }

                $this->writeMovement($grn->warehouse_id, $item->product_id, $item->variant_id, MovementType::RETURN_TO_SUPPLIER, (float) $item->qty_received, $qtyBefore, $qtyAfter, (float) $item->unit_cost_amount, (float) $wp->fresh()->wac_amount, StockReceipt::class, $grn->id, null, 'GRN void', $item->lot_id);
            }

            $grn->update(['status' => StockReceiptStatus::VOID]);

            return $grn->refresh();
        });

        $this->erp()->voidStockReceipt($grn);

        return $grn;
    }

    // -------------------------------------------------------------------------
    // Sale Orders
    // -------------------------------------------------------------------------

    public function createSaleOrder(array $data): SaleOrder
    {
        $so = DB::transaction(function () use ($data): SaleOrder {
            $tierCode = $this->normalizePriceTierCode($data['price_tier_code'] ?? PriceTierCode::B2B_RETAIL->value);
            $currency = strtoupper($data['currency'] ?? config('inventory.sale_defaults.currency', 'GBP'));
            $rate = (float) ($data['exchange_rate'] ?? $this->getExchangeRate($currency));
            $warehouseId = $data['warehouse_id'] ?? $this->defaultSaleWarehouseId();
            $customer = isset($data['customer_id']) ? Customer::findOrFail($data['customer_id']) : null;
            $documentType = $this->normalizeSaleDocumentType($data['document_type'] ?? null);
            $orderedAt = Carbon::parse($data['ordered_at'] ?? now());

            $taxLocal = (float) ($data['tax_local'] ?? 0);
            $discountLocal = (float) ($data['discount_local'] ?? 0);
            $shippingLocal = (float) ($data['shipping_local'] ?? 0);
            $lineItems = [];
            $subtotalLocal = 0.0;

            foreach ($data['items'] as $item) {
                [$productId, $variantId] = $this->resolveProductReference($item);
                $itemTierCode = isset($item['price_tier_code']) && trim((string) $item['price_tier_code']) !== ''
                    ? $this->normalizePriceTierCode($item['price_tier_code'])
                    : $tierCode;

                $fromDamaged = (bool) ($item['from_damaged'] ?? false);

                $unitPriceBdt = isset($item['unit_price_local'])
                    ? round((float) $item['unit_price_local'] * $rate, 4)
                    : (float) $this->resolvePrice($productId, $itemTierCode, $warehouseId, null, $variantId, $fromDamaged)->price_amount;

                $unitPriceLocal = round($unitPriceBdt / ($rate ?: 1), 4);
                $qty = (float) $item['qty_ordered'];
                $discountPct = (float) ($item['discount_pct'] ?? 0);
                $lineTotalLocal = round($qty * $unitPriceLocal * (1 - $discountPct / 100), 4);
                $lineTotalBdt = round($lineTotalLocal * $rate, 4);
                $subtotalLocal += $lineTotalLocal;

                $lineItems[] = [
                    'product_id'        => $productId,
                    'variant_id'        => $variantId,
                    'price_tier_code'   => $itemTierCode,
                    'qty_ordered'       => $qty,
                    'qty_fulfilled'     => 0,
                    'from_damaged'      => $fromDamaged,
                    'unit_price_local'  => $unitPriceLocal,
                    'unit_price_amount' => $unitPriceBdt,
                    'unit_cost_amount'  => 0,
                    'discount_pct'      => $discountPct,
                    'line_total_local'  => $lineTotalLocal,
                    'line_total_amount' => $lineTotalBdt,
                    'notes'             => $item['notes'] ?? null,
                ];
            }

            $subtotalBdt = round($subtotalLocal * $rate, 4);
            $coupon = $this->resolveCouponDiscount(
                $data['coupon_code'] ?? null,
                $subtotalLocal,
                $currency,
                $orderedAt,
                $documentType,
            );
            $shippingAmount = round($shippingLocal * $rate, 4);
            $totalLocal = $subtotalLocal + $taxLocal + $shippingLocal - $discountLocal - $coupon['coupon_discount_local'];
            $totalBdt = $subtotalBdt + round($taxLocal * $rate, 4) + $shippingAmount - round($discountLocal * $rate, 4) - $coupon['coupon_discount_amount'];
            $credit = $this->resolveCreditOverride($customer, $totalBdt, $data);

            $createdBy = $this->currentUserId() ?? ($data['created_by'] ?? null);
            $assignment = $this->salesAssignment($data, $customer, $createdBy);

            $so = SaleOrder::create([
                'so_number'             => $this->nextNumber($documentType === 'quotation' ? 'QT' : 'SO', SaleOrder::class, 'so_number'),
                'document_type'         => $documentType,
                'warehouse_id'          => $warehouseId,
                'customer_id'           => $data['customer_id'] ?? null,
                'coupon_id'             => $coupon['coupon_id'],
                'price_tier_code'       => $tierCode,
                'coupon_code'           => $coupon['coupon_code'],
                'coupon_name'           => $coupon['coupon_name'],
                'coupon_discount_type'  => $coupon['coupon_discount_type'],
                'coupon_discount_value' => $coupon['coupon_discount_value'],
                'currency'              => $currency,
                'exchange_rate'         => $rate,
                'status'                => SaleOrderStatus::DRAFT,
                'ordered_at'            => $orderedAt,
                'notes'                 => $data['notes'] ?? null,
                'created_by'            => $createdBy,
                ...$assignment,
                'tax_local'                     => $taxLocal,
                'tax_amount'                    => round($taxLocal * $rate, 4),
                'discount_local'                => $discountLocal,
                'discount_amount'               => round($discountLocal * $rate, 4),
                'shipping_local'                => $shippingLocal,
                'shipping_amount'               => $shippingAmount,
                'coupon_discount_local'         => $coupon['coupon_discount_local'],
                'coupon_discount_amount'        => $coupon['coupon_discount_amount'],
                'subtotal_local'                => $subtotalLocal,
                'subtotal_amount'               => $subtotalBdt,
                'total_local'                   => $totalLocal,
                'total_amount'                  => $totalBdt,
                'credit_limit_amount'           => $credit['credit_limit_amount'],
                'credit_exposure_before_amount' => $credit['credit_exposure_before_amount'],
                'credit_exposure_after_amount'  => $credit['credit_exposure_after_amount'],
                'credit_override_required'      => $credit['credit_override_required'],
                'credit_override_approved_by'   => $credit['credit_override_approved_by'],
                'credit_override_approved_at'   => $credit['credit_override_approved_at'],
                'credit_override_notes'         => $credit['credit_override_notes'],
                'cogs_amount'                   => 0,
            ]);

            foreach ($lineItems as $lineItem) {
                SaleOrderItem::create([
                    'sale_order_id'     => $so->id,
                    'product_id'        => $lineItem['product_id'],
                    'variant_id'        => $lineItem['variant_id'],
                    'price_tier_code'   => $lineItem['price_tier_code'],
                    'qty_ordered'       => $lineItem['qty_ordered'],
                    'qty_fulfilled'     => $lineItem['qty_fulfilled'],
                    'from_damaged'      => $lineItem['from_damaged'],
                    'unit_price_local'  => $lineItem['unit_price_local'],
                    'unit_price_amount' => $lineItem['unit_price_amount'],
                    'unit_cost_amount'  => $lineItem['unit_cost_amount'],
                    'discount_pct'      => $lineItem['discount_pct'],
                    'line_total_local'  => $lineItem['line_total_local'],
                    'line_total_amount' => $lineItem['line_total_amount'],
                    'notes'             => $lineItem['notes'],
                ]);
            }

            return $so->fresh(['customer', 'items.product']);
        });

        $this->erp()->syncSaleOrderDocument($so);

        return $so;
    }

    public function createSaleOrderFromQuotation(int $quotationId, array $overrides = []): SaleOrder
    {
        $quotation = SaleOrder::query()
            ->with(['items'])
            ->where('document_type', 'quotation')
            ->findOrFail($quotationId);

        if ($quotation->status === SaleOrderStatus::CANCELLED) {
            throw new InvalidTransitionException("Quotation #{$quotation->so_number} has been cancelled and cannot be converted.");
        }

        $metadata = $this->documentMetadata($quotation);
        $convertedSaleOrderId = (int) ($metadata['converted_sale_order_id'] ?? 0);

        if ($convertedSaleOrderId > 0) {
            $existingSaleOrder = SaleOrder::query()
                ->where('document_type', 'order')
                ->find($convertedSaleOrderId);

            if ($existingSaleOrder) {
                return $existingSaleOrder->fresh(['customer', 'items.product']);
            }
        }

        $saleOrder = $this->createSaleOrder([
            'warehouse_id'    => (int) ($overrides['warehouse_id'] ?? $quotation->warehouse_id),
            'customer_id'     => $overrides['customer_id'] ?? $quotation->customer_id,
            'price_tier_code' => (string) ($overrides['price_tier_code'] ?? $quotation->price_tier_code),
            'coupon_code'     => $overrides['coupon_code'] ?? $quotation->coupon_code,
            'currency'        => (string) ($overrides['currency'] ?? $quotation->currency),
            'exchange_rate'   => (float) ($overrides['exchange_rate'] ?? $quotation->exchange_rate),
            'document_type'   => 'order',
            'ordered_at'      => $overrides['ordered_at'] ?? now(),
            'notes'           => $overrides['notes'] ?? $this->appendConversionNote($quotation->notes, "Converted from quotation {$quotation->so_number}."),
            'created_by'      => $overrides['created_by'] ?? $quotation->created_by,
            'tax_local'       => (float) ($overrides['tax_local'] ?? $quotation->tax_local),
            'discount_local'  => (float) ($overrides['discount_local'] ?? $quotation->discount_local),
            'shipping_local'  => (float) ($overrides['shipping_local'] ?? $quotation->shipping_local),
            'items'           => collect($overrides['items'] ?? $quotation->items)
                ->map(function ($item): array {
                    return [
                        'product_id'       => (int) $item['product_id'],
                        'variant_id'       => $item['variant_id'] !== null ? (int) $item['variant_id'] : null,
                        'price_tier_code'  => (string) $item['price_tier_code'],
                        'qty_ordered'      => (float) $item['qty_ordered'],
                        'unit_price_local' => (float) $item['unit_price_local'],
                        'discount_pct'     => (float) $item['discount_pct'],
                        'notes'            => $item['notes'] ?? null,
                    ];
                })
                ->values()
                ->all(),
        ]);

        $this->putDocumentMetadata($quotation, array_merge($metadata, [
            'converted_sale_order_id'     => $saleOrder->getKey(),
            'converted_sale_order_number' => $saleOrder->so_number,
            'converted_sale_order_at'     => now()->toIso8601String(),
        ]));

        $this->putDocumentMetadata($saleOrder, array_merge($this->documentMetadata($saleOrder), [
            'source_quotation_id'     => $quotation->getKey(),
            'source_quotation_number' => $quotation->so_number,
        ]));

        return $saleOrder;
    }

    public function resolveCouponDiscount(?string $couponCode, float $subtotalLocal, string $currency, DateTimeInterface|string|null $orderedAt = null, ?string $documentType = 'order', ?int $ignoreSaleOrderId = null): array
    {
        $normalizedCode = $this->normalizeCouponCode($couponCode);

        if ($normalizedCode === null) {
            return [
                'coupon'                 => null,
                'coupon_id'              => null,
                'coupon_code'            => null,
                'coupon_name'            => null,
                'coupon_discount_type'   => null,
                'coupon_discount_value'  => 0.0,
                'coupon_discount_local'  => 0.0,
                'coupon_discount_amount' => 0.0,
            ];
        }

        $orderedAt = $orderedAt instanceof DateTimeInterface
            ? Carbon::parse($orderedAt->format('Y-m-d H:i:s'))
            : Carbon::parse($orderedAt ?? now());

        /** @var Coupon|null $coupon */
        $coupon = Coupon::query()
            ->whereRaw('UPPER(code) = ?', [$normalizedCode])
            ->first();

        if (!$coupon || !$coupon->is_active) {
            throw ValidationException::withMessages([
                'coupon_code' => 'The selected coupon code is not valid.',
            ]);
        }

        if ($coupon->starts_at && $coupon->starts_at->gt($orderedAt)) {
            throw ValidationException::withMessages([
                'coupon_code' => 'This coupon is not active yet.',
            ]);
        }

        if ($coupon->ends_at && $coupon->ends_at->lt($orderedAt)) {
            throw ValidationException::withMessages([
                'coupon_code' => 'This coupon has expired.',
            ]);
        }

        $subtotalAmount = round($this->convertToBase($subtotalLocal, $currency, $orderedAt->toDateString()), 4);
        $minimumSubtotalAmount = (float) ($coupon->minimum_subtotal_amount ?? 0);

        if ($minimumSubtotalAmount > 0 && $subtotalAmount < $minimumSubtotalAmount) {
            throw ValidationException::withMessages([
                'coupon_code' => 'This coupon requires a higher order subtotal.',
            ]);
        }

        if ($coupon->usage_limit !== null && $documentType !== 'quotation') {
            $usageCount = SaleOrder::query()
                ->where('coupon_id', $coupon->getKey())
                ->where('document_type', '!=', 'quotation')
                ->when($ignoreSaleOrderId !== null, fn ($query) => $query->whereKeyNot($ignoreSaleOrderId))
                ->count();

            if ($usageCount >= $coupon->usage_limit) {
                throw ValidationException::withMessages([
                    'coupon_code' => 'This coupon has reached its usage limit.',
                ]);
            }
        }

        $discountAmount = match ($coupon->discount_type) {
            'percent' => round($subtotalAmount * ((float) $coupon->discount_value / 100), 4),
            'fixed'   => round((float) $coupon->discount_value, 4),
            default   => throw new \InvalidArgumentException("Unknown coupon discount type [{$coupon->discount_type}]."),
        };

        $maximumDiscountAmount = (float) ($coupon->maximum_discount_amount ?? 0);

        if ($maximumDiscountAmount > 0) {
            $discountAmount = min($discountAmount, $maximumDiscountAmount);
        }

        $discountAmount = min($discountAmount, $subtotalAmount);
        $discountLocal = round($this->convertFromBase($discountAmount, $currency, $orderedAt->toDateString()), 4);

        return [
            'coupon'                 => $coupon,
            'coupon_id'              => $coupon->getKey(),
            'coupon_code'            => $coupon->code,
            'coupon_name'            => $coupon->name,
            'coupon_discount_type'   => $coupon->discount_type,
            'coupon_discount_value'  => round((float) $coupon->discount_value, 4),
            'coupon_discount_local'  => $discountLocal,
            'coupon_discount_amount' => round($discountAmount, 4),
        ];
    }

    public function normalizeCouponCode(?string $couponCode): ?string
    {
        $couponCode = strtoupper(trim((string) $couponCode));

        return $couponCode !== '' ? $couponCode : null;
    }

    private function normalizePriceTierCode(string $tierCode): string
    {
        $tier = PriceTierCode::tryFrom($tierCode);

        if (!$tier) {
            throw new \InvalidArgumentException("Unknown price tier [{$tierCode}].");
        }

        return $tier->value;
    }

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

            $saleReturn = SaleReturn::create([
                'return_number' => $this->nextNumber('SRT', SaleReturn::class, 'return_number'),
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
                    $maxReturnable = max(0.0, round((float) $saleOrderItem->qty_fulfilled - $alreadyReturned, 4));

                    $referenceKey = $productId . ':' . (int) ($variantId ?? 0);

                    if (($requestedQuantities[$referenceKey] ?? 0.0) > $maxReturnable + (float) config('inventory.qty_tolerance', 0.0001)) {
                        throw ValidationException::withMessages([
                            'items' => ["Return quantity for product [{$productId}] exceeds the fulfilled quantity still available to return."],
                        ]);
                    }
                }

                $unitPrice = isset($item['unit_price_amount'])
                    ? round((float) $item['unit_price_amount'], 4)
                    : round((float) ($saleOrderItem?->unit_price_amount ?? 0), 4);
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

        return DB::transaction(function () use ($saleReturn): SaleReturn {
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

            $purchaseReturn = PurchaseReturn::create([
                'return_number'     => $this->nextNumber('PRT', PurchaseReturn::class, 'return_number'),
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

                    if (($requestedQuantities[$referenceKey] ?? 0.0) > $maxReturnable + (float) config('inventory.qty_tolerance', 0.0001)) {
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

        return DB::transaction(function () use ($purchaseReturn): PurchaseReturn {
            foreach ($purchaseReturn->items as $item) {
                $warehouseProduct = $this->lockWarehouseProduct($purchaseReturn->warehouse_id, $item->product_id, $item->variant_id);
                $qty = (float) $item->qty_returned;
                $qtyBefore = (float) $warehouseProduct->qty_on_hand;

                if ($qtyBefore + (float) config('inventory.qty_tolerance', 0.0001) < $qty) {
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
    }

    public function confirmSaleOrder(int $soId): SaleOrder
    {
        $so = SaleOrder::findOrFail($soId);
        $this->assertSaleOrderAccess($so);
        $this->assertTransition($so->status, SaleOrderStatus::CONFIRMED, "sale order #{$soId}");
        $so->update(['status' => SaleOrderStatus::CONFIRMED]);
        $this->erp()->syncSaleOrderDocument($so->fresh(['customer', 'items.product']));

        return $so;
    }

    /** Reserve stock: increment qty_reserved for each line item. */
    public function reserveStock(int $soId): SaleOrder
    {
        $so = SaleOrder::with('items')->findOrFail($soId);
        $this->assertSaleOrderAccess($so);

        if (!in_array($so->status, [SaleOrderStatus::CONFIRMED, SaleOrderStatus::PROCESSING])) {
            throw new InvalidTransitionException("Cannot reserve stock for sale order in status [{$so->status->value}].");
        }

        return DB::transaction(function () use ($so): SaleOrder {
            foreach ($so->items as $item) {
                $wp = WarehouseProduct::where('warehouse_id', $so->warehouse_id)
                    ->where('product_id', $item->product_id)
                    ->where('variant_id', $item->variant_id)
                    ->lockForUpdate()
                    ->first();

                if (!$wp) {
                    throw new InsufficientStockException("Product [{$item->product_id}] not found in warehouse [{$so->warehouse_id}].");
                }

                $available = (float) $wp->qty_on_hand - (float) $wp->qty_reserved;

                if ($available < (float) $item->qty_ordered - (float) config('inventory.qty_tolerance', 0.0001)) {
                    throw new InsufficientStockException("Insufficient stock for product [{$item->product_id}]: available {$available}, required {$item->qty_ordered}.");
                }

                $wp->increment('qty_reserved', $item->qty_ordered);
            }

            $so->update(['status' => SaleOrderStatus::PROCESSING]);

            return $so->refresh();
        });
    }

    /**
     * Fulfill sale order: decrement stock, record COGS at WAC.
     * $fulfilledQtys = [sale_order_item_id => qty] — omit to fulfill all.
     */
    public function fulfillSaleOrder(int $soId, array $fulfilledQtys = []): SaleOrder
    {
        $so = SaleOrder::with('items.product')->findOrFail($soId);
        $this->assertSaleOrderAccess($so);

        if (!in_array($so->status, [SaleOrderStatus::PROCESSING, SaleOrderStatus::PARTIAL])) {
            throw new InvalidTransitionException("Sale order #{$soId} cannot be fulfilled from status [{$so->status->value}].");
        }

        $totalCogs = 0.0;

        $so = DB::transaction(function () use ($so, $fulfilledQtys, &$totalCogs): SaleOrder {
            $fullyFulfilled = true;

            foreach ($so->items as $item) {
                // $fulfilledQtys supports two formats:
                //   legacy:  [item_id => qty]
                //   with lot: [item_id => ['qty' => x, 'lot_id' => y, 'serial_ids' => [...]]]
                $raw = $fulfilledQtys[$item->id] ?? null;
                $qty = $raw === null
                    ? ((float) $item->qty_ordered - (float) $item->qty_fulfilled)
                    : (float) (is_array($raw) ? ($raw['qty'] ?? 0) : $raw);

                $lotId      = is_array($raw) ? ($raw['lot_id'] ?? null) : null;
                $serialIds  = is_array($raw) ? ($raw['serial_ids'] ?? []) : [];
                $fromDamaged = (bool) ($item->from_damaged ?? (is_array($raw) ? ($raw['from_damaged'] ?? false) : false));

                if ($qty <= 0) {
                    continue;
                }

                $remainingToFulfill = max(0.0, (float) $item->qty_ordered - (float) $item->qty_fulfilled);

                if ($qty > $remainingToFulfill + (float) config('inventory.qty_tolerance', 0.0001)) {
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

                    if ($qtyDamaged + (float) config('inventory.qty_tolerance', 0.0001) < $qty) {
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

                    if ((float) $item->qty_fulfilled + $qty < (float) $item->qty_ordered - (float) config('inventory.qty_tolerance')) {
                        $fullyFulfilled = false;
                    }

                    // Use qty_on_hand as before/after context since damaged bin is separate
                    $qtyBefore = (float) $wp->qty_on_hand;
                    $this->writeMovement($so->warehouse_id, $item->product_id, $item->variant_id, MovementType::SALE_FULFILLMENT, $qty, $qtyBefore, $qtyBefore, $wac, $wac, SaleOrder::class, $so->id, null, 'from damaged stock', $lotId);

                    continue;
                }

                $qtyBefore = (float) $wp->qty_on_hand;
                $reservedBefore = (float) $wp->qty_reserved;

                if ($qtyBefore + (float) config('inventory.qty_tolerance', 0.0001) < $qty) {
                    throw new InsufficientStockException("Insufficient on-hand stock for sale order item [{$item->id}]: available {$qtyBefore}, requested {$qty}.");
                }

                if ($reservedBefore + (float) config('inventory.qty_tolerance', 0.0001) < $qty) {
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

                    if ((float) $lot->qty_on_hand + (float) config('inventory.qty_tolerance', 0.0001) < $qty) {
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

                if ((float) $item->qty_fulfilled + $qty < (float) $item->qty_ordered - (float) config('inventory.qty_tolerance')) {
                    $fullyFulfilled = false;
                }

                $this->writeMovement($so->warehouse_id, $item->product_id, $item->variant_id, MovementType::SALE_FULFILLMENT, $qty, $qtyBefore, $qtyAfter, $wac, $wac, SaleOrder::class, $so->id, null, null, $lotId);
            }

            $so->update(['status' => $fullyFulfilled ? SaleOrderStatus::FULFILLED : SaleOrderStatus::PARTIAL, 'cogs_amount' => (float) $so->cogs_amount + $totalCogs]);

            return $so->refresh();
        });

        $this->erp()->postSaleFulfillment($so, $totalCogs);

        return $so;
    }

    public function cancelSaleOrder(int $soId): SaleOrder
    {
        $so = SaleOrder::with('items')->findOrFail($soId);
        $this->assertSaleOrderAccess($so);
        $this->assertTransition($so->status, SaleOrderStatus::CANCELLED, "sale order #{$soId}");

        return DB::transaction(function () use ($so): SaleOrder {
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

            $so->update(['status' => SaleOrderStatus::CANCELLED]);

            return $so->refresh();
        });
    }

    // -------------------------------------------------------------------------
    // Inter-Warehouse Transfers
    // -------------------------------------------------------------------------

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

            $transfer = Transfer::create([
                'transfer_number'      => $this->nextNumber('TRF', Transfer::class, 'transfer_number'),
                'from_warehouse_id'    => $data['from_warehouse_id'],
                'to_warehouse_id'      => $data['to_warehouse_id'],
                'status'               => TransferStatus::DRAFT,
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
                    $sourceWp = $this->getOrCreateWarehouseProduct($data['from_warehouse_id'], $product->id, $variantId);

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

            foreach ($createdBoxItems as $boxItem) {
                $allocatedShipping = $totalWeightKg > 0
                    ? round(($boxItem['allocated_weight_kg'] / $totalWeightKg) * $shippingCost, 4)
                    : 0.0;
                $unitLanded = $boxItem['qty_sent'] > 0
                    ? round((($boxItem['source_unit_cost_amount'] * $boxItem['qty_sent']) + $allocatedShipping) / $boxItem['qty_sent'], 4)
                    : 0.0;

                $boxItem['model']->update([
                    'shipping_allocated_amount' => $allocatedShipping,
                    'unit_landed_cost_amount'   => $unitLanded,
                ]);
            }

            foreach ($aggregates as $aggregate) {
                $qtySent = round((float) $aggregate['qty_sent'], 4);
                $unitCostSourceAmount = $qtySent > 0
                    ? round((float) $aggregate['source_cost_total'] / $qtySent, 4)
                    : 0.0;
                $allocatedShipping = $totalWeightKg > 0
                    ? round(((float) $aggregate['weight_kg_total'] / $totalWeightKg) * $shippingCost, 4)
                    : 0.0;
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
        $transfer = Transfer::with('items.product')->findOrFail($transferId);
        $this->assertTransition($transfer->status, TransferStatus::IN_TRANSIT, "transfer #{$transferId}");

        return DB::transaction(function () use ($transfer): Transfer {
            foreach ($transfer->items as $item) {
                $wp = WarehouseProduct::where('warehouse_id', $transfer->from_warehouse_id)
                    ->where('product_id', $item->product_id)
                    ->where('variant_id', $item->variant_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $available = (float) $wp->qty_on_hand - (float) $wp->qty_reserved;

                if ($available < (float) $item->qty_sent - (float) config('inventory.qty_tolerance')) {
                    throw new InsufficientStockException("Insufficient stock for transfer: product [{$item->product_id}] available {$available}, needed {$item->qty_sent}.");
                }

                $qtyBefore = (float) $wp->qty_on_hand;
                $qtyAfter = $qtyBefore - (float) $item->qty_sent;

                $wp->update([
                    'qty_on_hand'    => $qtyAfter,
                    'qty_in_transit' => (float) $wp->qty_in_transit + (float) $item->qty_sent,
                ]);

                $item->update(['wac_source_before_amount' => (float) $wp->wac_amount]);

                $this->writeMovement($transfer->from_warehouse_id, $item->product_id, $item->variant_id, MovementType::TRANSFER_OUT, (float) $item->qty_sent, $qtyBefore, $qtyAfter, (float) $item->unit_cost_source_amount, (float) $wp->wac_amount, Transfer::class, $transfer->id);
            }

            $transfer->update(['status' => TransferStatus::IN_TRANSIT, 'shipped_at' => now()]);

            return $transfer->refresh();
        });
    }

    /**
     * Receive transfer at destination: update destination WAC with landed cost.
     * $receivedQtys = [transfer_item_id => qty] — omit to receive full qty_sent.
     */
    public function receiveTransfer(int $transferId, array $receivedQtys = []): Transfer
    {
        $transfer = Transfer::with('items.product')->findOrFail($transferId);

        if (!in_array($transfer->status, [TransferStatus::IN_TRANSIT, TransferStatus::PARTIAL])) {
            throw new InvalidTransitionException("Transfer #{$transferId} is not in transit.");
        }

        return DB::transaction(function () use ($transfer, $receivedQtys): Transfer {
            $fullyReceived = true;

            foreach ($transfer->items as $item) {
                $remainingQty = max(0.0, (float) $item->qty_sent - (float) $item->qty_received);
                $qtyReceived = isset($receivedQtys[$item->id])
                    ? (float) $receivedQtys[$item->id]
                    : $remainingQty;

                if ($qtyReceived <= 0) {
                    continue;
                }

                if ($qtyReceived > $remainingQty + (float) config('inventory.qty_tolerance', 0.0001)) {
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

                if ($totalReceived < (float) $item->qty_sent - (float) config('inventory.qty_tolerance')) {
                    $fullyReceived = false;
                }

                $this->writeMovement($transfer->to_warehouse_id, $item->product_id, MovementType::TRANSFER_IN, $qtyReceived, $destQtyBefore, $destQtyAfter, (float) $item->unit_landed_cost_amount, $newDestWac, Transfer::class, $transfer->id);
            }

            $newStatus = $fullyReceived ? TransferStatus::RECEIVED : TransferStatus::PARTIAL;
            $transfer->update(['status' => $newStatus, 'received_at' => $fullyReceived ? now() : $transfer->received_at]);

            return $transfer->refresh();
        });
    }

    // -------------------------------------------------------------------------
    // Adjustments
    // -------------------------------------------------------------------------

    /**
     * Create a draft adjustment against a warehouse.
     * $items = [['product_id' => x, 'qty_actual' => y], ...]
     */
    public function createAdjustment(array $data): Adjustment
    {
        return DB::transaction(function () use ($data): Adjustment {
            $adjustment = Adjustment::create([
                'adjustment_number' => $this->nextNumber('ADJ', Adjustment::class, 'adjustment_number'),
                'warehouse_id'      => $data['warehouse_id'],
                'reason'            => $data['reason'],
                'notes'             => $data['notes'] ?? null,
                'status'            => StockReceiptStatus::DRAFT,
                'adjusted_at'       => $data['adjusted_at'] ?? now(),
                'created_by'        => $data['created_by'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $wp = $this->getOrCreateWarehouseProduct($data['warehouse_id'], $item['product_id']);
                $qtySystem = (float) $wp->qty_on_hand;
                $qtyActual = (float) $item['qty_actual'];

                AdjustmentItem::create([
                    'adjustment_id'    => $adjustment->id,
                    'product_id'       => $item['product_id'],
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
        $adjustment = Adjustment::with('items')->findOrFail($adjustmentId);

        if ($adjustment->status !== StockReceiptStatus::DRAFT) {
            throw new InvalidTransitionException("Adjustment #{$adjustmentId} is already {$adjustment->status->value}.");
        }

        $adjustment = DB::transaction(function () use ($adjustment): Adjustment {
            foreach ($adjustment->items as $item) {
                if (abs((float) $item->qty_delta) < (float) config('inventory.qty_tolerance')) {
                    continue;
                }

                $wp = WarehouseProduct::where('warehouse_id', $adjustment->warehouse_id)
                    ->where('product_id', $item->product_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $qtyBefore = (float) $wp->qty_on_hand;
                $qtyAfter = max(0.0, $qtyBefore + (float) $item->qty_delta);
                $wp->update(['qty_on_hand' => $qtyAfter]);

                $type = (float) $item->qty_delta > 0 ? MovementType::ADJUSTMENT_IN : MovementType::ADJUSTMENT_OUT;

                $this->writeMovement($adjustment->warehouse_id, $item->product_id, $type, abs((float) $item->qty_delta), $qtyBefore, $qtyAfter, (float) $item->unit_cost_amount, (float) $wp->fresh()->wac_amount, Adjustment::class, $adjustment->id);
            }

            $adjustment->update(['status' => StockReceiptStatus::POSTED]);

            return $adjustment->refresh();
        });

        $this->erp()->postAdjustment($adjustment);

        return $adjustment;
    }

    // -------------------------------------------------------------------------
    // Pick-Pack-Ship
    // -------------------------------------------------------------------------

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
            $pickList = PickList::create([
                'pick_number'  => $this->nextNumber('PIC', PickList::class, 'pick_number'),
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
                    'pick_list_id'      => $pickList->id,
                    'sale_order_item_id' => $item->id,
                    'product_id'        => $item->product_id,
                    'variant_id'        => $item->variant_id,
                    'lot_id'            => $item->lot_id,
                    'bin_location'      => $wp?->bin_location,
                    'qty_to_pick'       => $remainingQty,
                    'qty_picked'        => 0,
                ]);
            }

            return $pickList->refresh();
        });
    }

    /** Mark a pick list as actively being picked (draft → picking). */
    public function startPicking(int $pickListId): PickList
    {
        $pickList = PickList::findOrFail($pickListId);

        if ($pickList->status !== 'draft') {
            throw new InvalidTransitionException("Pick list #{$pickListId} is not in draft status.");
        }

        $pickList->update(['status' => 'picking']);

        return $pickList->refresh();
    }

    /**
     * Confirm pick: record actual quantities picked.
     * $pickedQtys = [pick_list_item_id => ['qty_picked' => x, 'lot_id' => y, 'serial_numbers' => [...]]]
     * Transitions status: picking → picked
     */
    public function confirmPick(int $pickListId, array $pickedQtys): PickList
    {
        $pickList = PickList::with('items')->findOrFail($pickListId);

        if ($pickList->status !== 'picking') {
            throw new InvalidTransitionException("Pick list #{$pickListId} must be in 'picking' status to confirm.");
        }

        return DB::transaction(function () use ($pickList, $pickedQtys): PickList {
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
            $shipment = Shipment::create([
                'shipment_number'       => $this->nextNumber('SHP', Shipment::class, 'shipment_number'),
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
        $shipment = Shipment::with('items')->findOrFail($shipmentId);

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
            'status'       => 'dispatched',
            'dispatched_at' => now(),
        ]);

        return $shipment->refresh();
    }

    /** Mark a shipment as delivered. */
    public function markShipmentDelivered(int $shipmentId): Shipment
    {
        $shipment = Shipment::findOrFail($shipmentId);

        if ($shipment->status !== 'dispatched') {
            throw new InvalidTransitionException("Shipment #{$shipmentId} must be dispatched before marking delivered.");
        }

        $shipment->update(['status' => 'delivered']);

        return $shipment->refresh();
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
                ? $exposure > $limit + (float) config('inventory.qty_tolerance', 0.0001)
                : $exposure > (float) config('inventory.qty_tolerance', 0.0001),
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
     */
    public function listCustomers(bool $activeOnly = false, ?string $search = null): Collection
    {
        return Customer::query()
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
            $next = Customer::query()->max('id') + 1;
            $data['code'] = 'CUS-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
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
            ->when($excludeTerminal, fn ($q) => $q->whereNotIn('status', [SaleOrderStatus::FULFILLED->value, SaleOrderStatus::CANCELLED->value, SaleOrderStatus::RETURNED->value]))
            ->when($from, fn ($q) => $q->whereDate('ordered_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('ordered_at', '<=', $to))
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

    public function customerAnalytics(int $customerId, int $lookbackDays = 180, int $forecastDays = 90): array
    {
        $lookbackDays = max(7, $lookbackDays);
        $forecastDays = max(7, $forecastDays);
        $historyEnd = now()->endOfDay();
        $historyStart = now()->copy()->subDays($lookbackDays - 1)->startOfDay();
        $observedDays = max(1, (int) floor($historyStart->diffInDays($historyEnd)) + 1);

        $ordersQuery = SaleOrder::query()
            ->with('items.product')
            ->where('document_type', 'order')
            ->where('customer_id', $customerId)
            ->whereBetween('ordered_at', [$historyStart, $historyEnd])
            ->whereNotIn('status', [
                SaleOrderStatus::CANCELLED->value,
                SaleOrderStatus::RETURNED->value,
            ]);

        CommercialTeamAccess::applySalesScope($ordersQuery);

        $orders = $ordersQuery->get();
        $customer = $orders->first()?->customer ?? Customer::query()->find($customerId);
        $qty = (float) $orders->sum(fn (SaleOrder $order): float => (float) $order->items->sum('qty_ordered'));
        $revenue = (float) $orders->sum('total_local');
        $avgDailyRevenue = $revenue > 0 ? $revenue / $observedDays : 0.0;
        $avgDailyQty = $qty > 0 ? $qty / $observedDays : 0.0;
        $lastOrder = $orders->sortByDesc(fn (SaleOrder $order) => $order->ordered_at?->getTimestamp() ?? 0)->first();

        return [
            'segment'           => $this->customerSegment($revenue, $orders->count()),
            'zone'              => $customer?->zone ?: 'Unassigned',
            'area'              => $customer?->area ?: 'Unassigned',
            'demographic'       => $customer?->demographic_segment ?: 'Unassigned',
            'demographic_data'  => $customer?->demographic_data ?: [],
            'orders_count'      => $orders->count(),
            'history_qty'       => round($qty, 2),
            'history_revenue'   => round($revenue, 2),
            'avg_order_value'   => $orders->count() > 0 ? round($revenue / $orders->count(), 2) : 0.0,
            'forecast_qty'      => round($avgDailyQty * $forecastDays, 2),
            'forecast_revenue'  => round($avgDailyRevenue * $forecastDays, 2),
            'last_order_at'     => $lastOrder?->ordered_at?->toDateString(),
            'days_since_order'  => $lastOrder?->ordered_at ? $lastOrder->ordered_at->diffInDays(now()) : null,
            'distinct_products' => $orders->flatMap(fn (SaleOrder $order) => $order->items->pluck('product_id'))->filter()->unique()->count(),
            'forecast_days'     => $forecastDays,
            'lookback_days'     => $lookbackDays,
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
        $invoiceClass = \Centrex\Accounting\Models\Invoice::class;

        if (!class_exists($invoiceClass)) {
            return 0.8;
        }

        $invoices = $invoiceClass::query()
            ->whereNotNull('inventory_sale_order_id')
            ->whereDate('invoice_date', '>=', $startDate->toDateString())
            ->whereDate('invoice_date', '<=', $endDate->toDateString())
            ->get();

        $total = (float) $invoices->sum('base_total');

        if ($total <= 0) {
            return 0.8;
        }

        return max(0.1, min(1.0, round((float) $invoices->sum('base_paid_amount') / $total, 4)));
    }

    private function historicalSupplierPaymentRatio(DateTimeInterface $startDate, DateTimeInterface $endDate): float
    {
        $billClass = \Centrex\Accounting\Models\Bill::class;

        if (!class_exists($billClass)) {
            return 0.7;
        }

        $bills = $billClass::query()
            ->whereNotNull('inventory_purchase_order_id')
            ->whereDate('bill_date', '>=', $startDate->toDateString())
            ->whereDate('bill_date', '<=', $endDate->toDateString())
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
            ? $creditExposureAfter > $creditLimit + (float) config('inventory.qty_tolerance', 0.0001)
            : $creditExposureAfter > (float) config('inventory.qty_tolerance', 0.0001);

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

        $creditOverrideRequested = (bool) ($data['credit_override'] ?? $data['credit_override_required'] ?? false);
        $approvedBy = $data['credit_override_approved_by'] ?? $data['created_by'] ?? $this->currentUserId();

        if (!$creditOverrideRequested) {
            throw new \InvalidArgumentException("Customer [{$customer->name}] exceeds credit limit. Exposure after this order would be {$creditExposureAfter} against a limit of {$creditLimit}.");
        }

        if (!$this->canApproveCreditOverride($approvedBy)) {
            throw new AuthorizationException('A higher authority approval is required to override the customer credit limit.');
        }

        return [
            'credit_limit_amount'           => $creditLimit,
            'credit_exposure_before_amount' => $creditExposureBefore,
            'credit_exposure_after_amount'  => $creditExposureAfter,
            'credit_override_required'      => true,
            'credit_override_approved_by'   => $approvedBy,
            'credit_override_approved_at'   => now(),
            'credit_override_notes'         => $data['credit_override_notes'] ?? null,
        ];
    }

    private function customerOutstandingExposure(int $customerId): float
    {
        return (float) (SaleOrder::query()
            ->where('customer_id', $customerId)
            ->whereNotIn('status', [
                SaleOrderStatus::CANCELLED->value,
                SaleOrderStatus::RETURNED->value,
            ])
            ->sum('total_amount'));
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
        return class_exists(\Centrex\ModelData\Data::class)
            && Schema::hasTable('model_datas');
    }

    private function documentMetadata(Model $model): array
    {
        if (!$this->modelDataReady()) {
            return [];
        }

        $record = \Centrex\ModelData\Data::query()
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

        \Centrex\ModelData\Data::putForModel($model, $metadata);
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

    /** Rotate the API key for a partner. Returns the partner with the new key. */
    public function rotatePartnerApiKey(int $partnerId): Partner
    {
        $partner = Partner::findOrFail($partnerId);
        $partner->update(['api_key' => Partner::generateApiKey()]);

        // Temporarily un-hide api_key for the response
        return $partner->makeVisible('api_key')->refresh();
    }

    public function listPartners(bool $activeOnly = true): Collection
    {
        return Partner::when($activeOnly, fn ($q) => $q->where('is_active', true))->get();
    }
}

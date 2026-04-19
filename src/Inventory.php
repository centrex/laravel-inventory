<?php

declare(strict_types = 1);

namespace Centrex\Inventory;

use Centrex\Inventory\Enums\{MovementType, PriceTierCode, PurchaseOrderStatus, SaleOrderStatus, StockReceiptStatus, TransferStatus};
use Centrex\Inventory\Exceptions\{InsufficientStockException, InvalidTransitionException, PriceNotFoundException};
use Centrex\Inventory\Models\{Adjustment, AdjustmentItem, Customer, ExchangeRate, PriceTier, Product, ProductPrice, PurchaseOrder, PurchaseOrderItem, SaleOrder, SaleOrderItem, StockMovement, StockReceipt, StockReceiptItem, Transfer, TransferBox, TransferBoxItem, TransferItem, Warehouse, WarehouseProduct};
use Centrex\Inventory\Support\ErpIntegration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB, Gate};

class Inventory
{
    // -------------------------------------------------------------------------
    // Exchange Rates
    // -------------------------------------------------------------------------

    public function setExchangeRate(string $currency, float $rate, ?string $date = null, string $source = 'manual'): ExchangeRate
    {
        $date ??= now()->toDateString();

        return ExchangeRate::updateOrCreate(
            ['currency' => strtoupper($currency), 'valid_at' => $date],
            ['rate' => $rate, 'source' => $source],
        );
    }

    public function getExchangeRate(string $currency, ?string $date = null): float
    {
        $currency = strtoupper($currency);

        if ($currency === strtoupper(config('inventory.base_currency', 'BDT'))) {
            return 1.0;
        }

        $date ??= now()->toDateString();

        $rate = ExchangeRate::where('currency', $currency)
            ->where('valid_at', '<=', $date)
            ->orderByDesc('valid_at')
            ->first();

        if (!$rate) {
            throw new \RuntimeException("No exchange rate found for currency [{$currency}] on or before [{$date}].");
        }

        return (float) $rate->rate;
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

    // -------------------------------------------------------------------------
    // Price Tiers
    // -------------------------------------------------------------------------

    public function seedPriceTiers(): void
    {
        foreach (PriceTierCode::cases() as $tier) {
            PriceTier::firstOrCreate(
                ['code' => $tier->value],
                ['name' => $tier->label(), 'sort_order' => $tier->sortOrder(), 'is_active' => true],
            );
        }
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
        $tier = PriceTier::where('code', $tierCode)->firstOrFail();

        $data = [
            'price_amount'   => $priceAmount,
            'price_local'    => $options['price_local'] ?? null,
            'currency'       => $options['currency'] ?? null,
            'effective_from' => $options['effective_from'] ?? null,
            'effective_to'   => $options['effective_to'] ?? null,
            'is_active'      => $options['is_active'] ?? true,
        ];

        return ProductPrice::updateOrCreate(
            [
                'product_id'     => $productId,
                'price_tier_id'  => $tier->id,
                'warehouse_id'   => $warehouseId,
                'effective_from' => $data['effective_from'],
            ],
            $data,
        );
    }

    /**
     * Resolve the effective sell price for a product + tier at a given warehouse.
     * Priority: warehouse-specific active price → global active price.
     */
    public function resolvePrice(int $productId, string $tierCode, int $warehouseId, ?string $date = null): ProductPrice
    {
        $tier = PriceTier::where('code', $tierCode)->firstOrFail();
        $date ??= now()->toDateString();

        $base = ProductPrice::where('product_id', $productId)
            ->where('price_tier_id', $tier->id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));

        $price = (clone $base)->where('warehouse_id', $warehouseId)->latest()->first();
        $price ??= (clone $base)->whereNull('warehouse_id')->latest()->first();

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
    public function getPriceSheet(int $productId, int $warehouseId, ?string $date = null): Collection
    {
        return PriceTier::where('is_active', true)->orderBy('sort_order')->get()
            ->map(function (PriceTier $tier) use ($productId, $warehouseId, $date) {
                try {
                    $price = $this->resolvePrice($productId, $tier->code, $warehouseId, $date);
                } catch (PriceNotFoundException) {
                    $price = null;
                }

                return [
                    'tier_code'    => $tier->code,
                    'tier_name'    => $tier->name,
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

    public function getOrCreateWarehouseProduct(int $warehouseId, int $productId): WarehouseProduct
    {
        return WarehouseProduct::firstOrCreate(
            ['warehouse_id' => $warehouseId, 'product_id' => $productId],
            ['qty_on_hand' => 0, 'qty_reserved' => 0, 'qty_in_transit' => 0, 'wac_amount' => 0],
        );
    }

    public function getStockLevel(int $productId, int $warehouseId): WarehouseProduct
    {
        return $this->getOrCreateWarehouseProduct($warehouseId, $productId);
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

    private function writeMovement(int $warehouseId, int $productId, MovementType $type, float $qty, float $qtyBefore, float $qtyAfter, ?float $unitCostAmount, ?float $wacAmount, ?string $refType, ?int $refId, ?int $createdBy = null, ?string $notes = null): StockMovement
    {
        return StockMovement::create([
            'warehouse_id'     => $warehouseId,
            'product_id'       => $productId,
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

    private function lockWarehouseProduct(int $warehouseId, int $productId): WarehouseProduct
    {
        $model = new WarehouseProduct();

        $existing = WarehouseProduct::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return $existing;
        }

        DB::connection($model->getConnectionName())->table($model->getTable())->insertOrIgnore([
            'warehouse_id'   => $warehouseId,
            'product_id'     => $productId,
            'qty_on_hand'    => 0,
            'qty_reserved'   => 0,
            'qty_in_transit' => 0,
            'wac_amount'     => 0,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return WarehouseProduct::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->firstOrFail();
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
            $rate = (float) ($data['exchange_rate'] ?? $this->getExchangeRate($data['currency']));

            $taxLocal = (float) ($data['tax_local'] ?? 0);
            $shippingLocal = (float) ($data['shipping_local'] ?? 0);

            $po = PurchaseOrder::create([
                'po_number'            => $this->nextNumber('PO', PurchaseOrder::class, 'po_number'),
                'warehouse_id'         => $data['warehouse_id'],
                'supplier_id'          => $data['supplier_id'],
                'currency'             => strtoupper($data['currency']),
                'exchange_rate'        => $rate,
                'status'               => PurchaseOrderStatus::DRAFT,
                'ordered_at'           => $data['ordered_at'] ?? null,
                'expected_at'          => $data['expected_at'] ?? null,
                'notes'                => $data['notes'] ?? null,
                'created_by'           => $data['created_by'] ?? null,
                'tax_local'            => $taxLocal,
                'tax_amount'           => round($taxLocal * $rate, 4),
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
                $unitPriceLocal = (float) $item['unit_price_local'];
                $qty = (float) $item['qty_ordered'];
                $unitPriceBdt = round($unitPriceLocal * $rate, 4);
                $lineTotalLocal = round($qty * $unitPriceLocal, 4);
                $lineTotalBdt = round($qty * $unitPriceBdt, 4);
                $subtotalLocal += $lineTotalLocal;

                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id'        => $item['product_id'],
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
            $totalLocal = $subtotalLocal + (float) $po->tax_local + (float) $po->shipping_local;
            $totalBdt = $subtotalBdt + (float) $po->tax_amount + (float) $po->shipping_amount + (float) $po->other_charges_amount;

            $po->update(['subtotal_local' => $subtotalLocal, 'subtotal_amount' => $subtotalBdt, 'total_local' => $totalLocal, 'total_amount' => $totalBdt]);

            return $po->refresh();
        });

        $this->erp()->syncPurchaseOrderDocument($po);

        return $po;
    }

    public function submitPurchaseOrder(int $poId): PurchaseOrder
    {
        $po = PurchaseOrder::findOrFail($poId);
        $this->assertTransition($po->status, PurchaseOrderStatus::SUBMITTED, "purchase order #{$poId}");
        $po->update(['status' => PurchaseOrderStatus::SUBMITTED, 'ordered_at' => $po->ordered_at ?? now()]);

        return $po;
    }

    public function confirmPurchaseOrder(int $poId): PurchaseOrder
    {
        $po = PurchaseOrder::findOrFail($poId);
        $this->assertTransition($po->status, PurchaseOrderStatus::CONFIRMED, "purchase order #{$poId}");
        $po->update(['status' => PurchaseOrderStatus::CONFIRMED]);
        $this->erp()->syncPurchaseOrderDocument($po->fresh(['supplier', 'items.product']));

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

                StockReceiptItem::create([
                    'stock_receipt_id'       => $grn->id,
                    'purchase_order_item_id' => $poItem->id,
                    'product_id'             => $poItem->product_id,
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
                $wp = $this->lockWarehouseProduct($grn->warehouse_id, $item->product_id);

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

                $this->writeMovement($grn->warehouse_id, $item->product_id, MovementType::PURCHASE_RECEIPT, (float) $item->qty_received, $qtyBefore, $qtyAfter, (float) $item->unit_cost_amount, $newWac, StockReceipt::class, $grn->id);
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

                $this->writeMovement($grn->warehouse_id, $item->product_id, MovementType::RETURN_TO_SUPPLIER, (float) $item->qty_received, $qtyBefore, $qtyAfter, (float) $item->unit_cost_amount, (float) $wp->fresh()->wac_amount, StockReceipt::class, $grn->id, null, 'GRN void');
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
            $tier = PriceTier::where('code', $data['price_tier_code'] ?? PriceTierCode::RETAIL->value)->firstOrFail();
            $rate = (float) ($data['exchange_rate'] ?? $this->getExchangeRate($data['currency']));
            $customer = isset($data['customer_id']) ? Customer::findOrFail($data['customer_id']) : null;

            $taxLocal = (float) ($data['tax_local'] ?? 0);
            $discountLocal = (float) ($data['discount_local'] ?? 0);
            $lineItems = [];
            $subtotalLocal = 0.0;

            foreach ($data['items'] as $item) {
                $itemTier = isset($item['price_tier_code'])
                    ? PriceTier::where('code', $item['price_tier_code'])->firstOrFail()
                    : $tier;

                $unitPriceBdt = isset($item['unit_price_local'])
                    ? round((float) $item['unit_price_local'] * $rate, 4)
                    : (float) $this->resolvePrice($item['product_id'], $itemTier->code, $data['warehouse_id'])->price_amount;

                $unitPriceLocal = round($unitPriceBdt / ($rate ?: 1), 4);
                $qty = (float) $item['qty_ordered'];
                $discountPct = (float) ($item['discount_pct'] ?? 0);
                $lineTotalLocal = round($qty * $unitPriceLocal * (1 - $discountPct / 100), 4);
                $lineTotalBdt = round($lineTotalLocal * $rate, 4);
                $subtotalLocal += $lineTotalLocal;

                $lineItems[] = [
                    'product_id'        => $item['product_id'],
                    'price_tier_id'     => $itemTier->id,
                    'qty_ordered'       => $qty,
                    'qty_fulfilled'     => 0,
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
            $totalLocal = $subtotalLocal + $taxLocal - $discountLocal;
            $totalBdt = $subtotalBdt + round($taxLocal * $rate, 4) - round($discountLocal * $rate, 4);
            $credit = $this->resolveCreditOverride($customer, $totalBdt, $data);

            $so = SaleOrder::create([
                'so_number'                    => $this->nextNumber('SO', SaleOrder::class, 'so_number'),
                'warehouse_id'                 => $data['warehouse_id'],
                'customer_id'                  => $data['customer_id'] ?? null,
                'price_tier_id'                => $tier->id,
                'currency'                     => strtoupper($data['currency']),
                'exchange_rate'                => $rate,
                'status'                       => SaleOrderStatus::DRAFT,
                'ordered_at'                   => $data['ordered_at'] ?? now(),
                'notes'                        => $data['notes'] ?? null,
                'created_by'                   => $data['created_by'] ?? null,
                'tax_local'                    => $taxLocal,
                'tax_amount'                   => round($taxLocal * $rate, 4),
                'discount_local'               => $discountLocal,
                'discount_amount'              => round($discountLocal * $rate, 4),
                'subtotal_local'               => $subtotalLocal,
                'subtotal_amount'              => $subtotalBdt,
                'total_local'                  => $totalLocal,
                'total_amount'                 => $totalBdt,
                'credit_limit_amount'          => $credit['credit_limit_amount'],
                'credit_exposure_before_amount'=> $credit['credit_exposure_before_amount'],
                'credit_exposure_after_amount' => $credit['credit_exposure_after_amount'],
                'credit_override_required'     => $credit['credit_override_required'],
                'credit_override_approved_by'  => $credit['credit_override_approved_by'],
                'credit_override_approved_at'  => $credit['credit_override_approved_at'],
                'credit_override_notes'        => $credit['credit_override_notes'],
                'cogs_amount'                  => 0,
            ]);

            foreach ($lineItems as $lineItem) {
                SaleOrderItem::create([
                    'sale_order_id'     => $so->id,
                    'product_id'        => $lineItem['product_id'],
                    'price_tier_id'     => $lineItem['price_tier_id'],
                    'qty_ordered'       => $lineItem['qty_ordered'],
                    'qty_fulfilled'     => $lineItem['qty_fulfilled'],
                    'unit_price_local'  => $lineItem['unit_price_local'],
                    'unit_price_amount' => $lineItem['unit_price_amount'],
                    'unit_cost_amount'  => $lineItem['unit_cost_amount'],
                    'discount_pct'      => $lineItem['discount_pct'],
                    'line_total_local'  => $lineItem['line_total_local'],
                    'line_total_amount' => $lineItem['line_total_amount'],
                    'notes'             => $lineItem['notes'],
                ]);
            }

            return $so->refresh();
        });

        $this->erp()->syncSaleOrderDocument($so);

        return $so;
    }

    public function confirmSaleOrder(int $soId): SaleOrder
    {
        $so = SaleOrder::findOrFail($soId);
        $this->assertTransition($so->status, SaleOrderStatus::CONFIRMED, "sale order #{$soId}");
        $so->update(['status' => SaleOrderStatus::CONFIRMED]);
        $this->erp()->syncSaleOrderDocument($so->fresh(['customer', 'items.product']));

        return $so;
    }

    /** Reserve stock: increment qty_reserved for each line item. */
    public function reserveStock(int $soId): SaleOrder
    {
        $so = SaleOrder::with('items')->findOrFail($soId);

        if (!in_array($so->status, [SaleOrderStatus::CONFIRMED, SaleOrderStatus::PROCESSING])) {
            throw new InvalidTransitionException("Cannot reserve stock for sale order in status [{$so->status->value}].");
        }

        return DB::transaction(function () use ($so): SaleOrder {
            foreach ($so->items as $item) {
                $wp = WarehouseProduct::where('warehouse_id', $so->warehouse_id)
                    ->where('product_id', $item->product_id)
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
        $so = SaleOrder::with('items')->findOrFail($soId);

        if (!in_array($so->status, [SaleOrderStatus::PROCESSING, SaleOrderStatus::PARTIAL])) {
            throw new InvalidTransitionException("Sale order #{$soId} cannot be fulfilled from status [{$so->status->value}].");
        }

        $totalCogs = 0.0;

        $so = DB::transaction(function () use ($so, $fulfilledQtys, &$totalCogs): SaleOrder {
            $fullyFulfilled = true;

            foreach ($so->items as $item) {
                $qty = isset($fulfilledQtys[$item->id])
                    ? (float) $fulfilledQtys[$item->id]
                    : ((float) $item->qty_ordered - (float) $item->qty_fulfilled);

                if ($qty <= 0) {
                    continue;
                }

                $remainingToFulfill = max(0.0, (float) $item->qty_ordered - (float) $item->qty_fulfilled);

                if ($qty > $remainingToFulfill + (float) config('inventory.qty_tolerance', 0.0001)) {
                    throw new \InvalidArgumentException("Cannot fulfill {$qty} units for sale order item [{$item->id}]; only {$remainingToFulfill} remain open.");
                }

                $wp = WarehouseProduct::where('warehouse_id', $so->warehouse_id)
                    ->where('product_id', $item->product_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $wac = (float) $wp->wac_amount;
                $qtyBefore = (float) $wp->qty_on_hand;
                $reservedBefore = (float) $wp->qty_reserved;

                if ($qtyBefore + (float) config('inventory.qty_tolerance', 0.0001) < $qty) {
                    throw new InsufficientStockException("Insufficient on-hand stock for sale order item [{$item->id}]: available {$qtyBefore}, requested {$qty}.");
                }

                if ($reservedBefore + (float) config('inventory.qty_tolerance', 0.0001) < $qty) {
                    throw new InsufficientStockException("Insufficient reserved stock for sale order item [{$item->id}]: reserved {$reservedBefore}, requested {$qty}.");
                }

                $qtyAfter = $qtyBefore - $qty;

                $wp->update([
                    'qty_on_hand'  => $qtyAfter,
                    'qty_reserved' => $reservedBefore - $qty,
                ]);

                $item->update(['qty_fulfilled' => (float) $item->qty_fulfilled + $qty, 'unit_cost_amount' => $wac]);
                $totalCogs += round($qty * $wac, 4);

                if ((float) $item->qty_fulfilled + $qty < (float) $item->qty_ordered - (float) config('inventory.qty_tolerance')) {
                    $fullyFulfilled = false;
                }

                $this->writeMovement($so->warehouse_id, $item->product_id, MovementType::SALE_FULFILLMENT, $qty, $qtyBefore, $qtyAfter, $wac, $wac, SaleOrder::class, $so->id);
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
        $this->assertTransition($so->status, SaleOrderStatus::CANCELLED, "sale order #{$soId}");

        return DB::transaction(function () use ($so): SaleOrder {
            if (in_array($so->status, [SaleOrderStatus::PROCESSING, SaleOrderStatus::PARTIAL], true)) {
                foreach ($so->items as $item) {
                    $reserved = (float) $item->qty_ordered - (float) $item->qty_fulfilled;

                    if ($reserved > 0) {
                        $wp = WarehouseProduct::where('warehouse_id', $so->warehouse_id)
                            ->where('product_id', $item->product_id)
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
                    'transfer_id'         => $transfer->id,
                    'box_code'            => $boxData['box_code'] ?? 'BOX-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                    'measured_weight_kg'  => $measuredWeight,
                    'notes'               => $boxData['notes'] ?? null,
                ]);

                $preparedItems = [];
                $theoreticalWeightTotal = 0.0;
                $fallbackQtyTotal = 0.0;

                foreach ($boxData['items'] as $item) {
                    $product = Product::findOrFail($item['product_id']);
                    $qty = round((float) $item['qty_sent'], 4);
                    $this->ensurePositiveQuantity($qty, 'qty_sent');

                    $theoreticalWeight = $product->weight_kg !== null
                        ? round($qty * (float) $product->weight_kg, 4)
                        : 0.0;
                    $sourceWp = $this->getOrCreateWarehouseProduct($data['from_warehouse_id'], $product->id);

                    $preparedItems[] = [
                        'product'                 => $product,
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
                        'transfer_box_id'         => $box->id,
                        'product_id'              => $preparedItem['product']->id,
                        'qty_sent'                => $preparedItem['qty_sent'],
                        'theoretical_weight_kg'   => $preparedItem['theoretical_weight_kg'],
                        'allocated_weight_kg'     => $allocatedWeight,
                        'weight_ratio'            => $weightRatio,
                        'source_unit_cost_amount' => $preparedItem['source_unit_cost_amount'],
                        'shipping_allocated_amount' => 0,
                        'unit_landed_cost_amount' => $preparedItem['source_unit_cost_amount'],
                        'notes'                   => $preparedItem['notes'],
                    ]);

                    $productId = $preparedItem['product']->id;
                    $aggregates[$productId] ??= [
                        'qty_sent'                => 0.0,
                        'weight_kg_total'         => 0.0,
                        'source_cost_total'       => 0.0,
                        'unit_cost_source_amount' => 0.0,
                    ];
                    $aggregates[$productId]['qty_sent'] += $preparedItem['qty_sent'];
                    $aggregates[$productId]['weight_kg_total'] += $allocatedWeight;
                    $aggregates[$productId]['source_cost_total'] += $preparedItem['source_unit_cost_amount'] * $preparedItem['qty_sent'];
                    $aggregates[$productId]['unit_cost_source_amount'] = $preparedItem['source_unit_cost_amount'];

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

            foreach ($aggregates as $productId => $aggregate) {
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
                    'product_id'                => $productId,
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

                $this->writeMovement($transfer->from_warehouse_id, $item->product_id, MovementType::TRANSFER_OUT, (float) $item->qty_sent, $qtyBefore, $qtyAfter, (float) $item->unit_cost_source_amount, (float) $wp->wac_amount, Transfer::class, $transfer->id);
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

                $destWp = $this->lockWarehouseProduct($transfer->to_warehouse_id, $item->product_id);

                $destWacBefore = (float) $destWp->wac_amount;
                $newDestWac = $this->recalculateWac($destWp, $qtyReceived, (float) $item->unit_landed_cost_amount);
                $destQtyBefore = (float) $destWp->qty_on_hand;
                $destQtyAfter = $destQtyBefore + $qtyReceived;

                $destWp->update(['qty_on_hand' => $destQtyAfter, 'wac_amount' => $newDestWac]);

                WarehouseProduct::where('warehouse_id', $transfer->from_warehouse_id)
                    ->where('product_id', $item->product_id)
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
            'customer_id'              => $customer->id,
            'credit_limit_amount'      => $limit,
            'outstanding_exposure'     => $exposure,
            'available_credit_amount'  => round($limit - $exposure, 4),
            'is_over_limit'            => $limit > 0
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
    // Helpers
    // -------------------------------------------------------------------------

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
}

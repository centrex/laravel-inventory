<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Concerns;

use Centrex\Inventory\Models\WarehouseProduct;
use Illuminate\Support\Collection;

trait ManagesStockLedger
{
    /**
     * Return (or create) the stock-ledger row for a warehouse/product/variant combination.
     * This is the non-locking read path — use {@see lockWarehouseProduct()} inside transactions.
     */
    public function getOrCreateWarehouseProduct(int $warehouseId, int $productId, ?int $variantId = null): WarehouseProduct
    {
        $variantId = $this->normalizeVariantId($variantId, $productId);

        return WarehouseProduct::firstOrCreate(
            ['warehouse_id' => $warehouseId, 'product_id' => $productId, 'variant_id' => $variantId],
            ['qty_on_hand' => 0, 'qty_reserved' => 0, 'qty_in_transit' => 0, 'wac_amount' => 0],
        );
    }

    /** Get the current stock position (on-hand, reserved, in-transit, WAC) for one product+warehouse. */
    public function getStockLevel(int $productId, int $warehouseId, ?int $variantId = null): WarehouseProduct
    {
        return $this->getOrCreateWarehouseProduct($warehouseId, $productId, $variantId);
    }

    /** Get stock positions for all products in a warehouse. */
    public function getStockLevels(int $warehouseId): Collection
    {
        return WarehouseProduct::with('product')->where('warehouse_id', $warehouseId)->get();
    }

    /** Return all WarehouseProduct rows where available qty ≤ reorder_point. Optionally scoped to one warehouse. */
    public function getLowStockItems(?int $warehouseId = null): Collection
    {
        return WarehouseProduct::with(['product', 'warehouse'])
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->whereNotNull('reorder_point')
            ->whereRaw('(qty_on_hand - qty_reserved) <= reorder_point')
            ->get();
    }

    /** Total inventory value (SUM of qty_on_hand × wac_amount) in base currency. Optionally scoped to one warehouse. */
    public function getStockValue(?int $warehouseId = null): float
    {
        return (float) (WarehouseProduct::when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->selectRaw('SUM(qty_on_hand * wac_amount) as total_value')
            ->value('total_value') ?? 0.0);
    }

    /** Total net saleable stock (SUM of qty_on_hand − qty_reserved) across products. Optionally scoped to one warehouse. */
    public function getNetSaleableStock(?int $warehouseId = null): float
    {
        // Alias must not be "net_saleable_stock" — that name collides with
        // WarehouseProduct::getNetSaleableStockAttribute(), and Eloquent always prefers an
        // accessor over the raw selected column, so ->value() would call the accessor instead
        // of returning this aggregate (and the accessor needs qty_on_hand/qty_reserved, which
        // this query never selects as individual columns).
        return (float) (WarehouseProduct::when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->selectRaw('SUM(qty_on_hand - qty_reserved) as net_saleable_stock_total')
            ->value('net_saleable_stock_total') ?? 0.0);
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Support;

use Centrex\Accounting\Contracts\InventorySnapshotProvider;
use Centrex\Accounting\Models\FiscalPeriod;
use Centrex\Inventory\Models\WarehouseProduct;

class AccountingInventorySnapshotProvider implements InventorySnapshotProvider
{
    /**
     * @return array{rows: list<array<string, mixed>>, inventory_account_code: string}
     */
    public function snapshotForPeriod(FiscalPeriod $period, string $currency): array
    {
        $rows = WarehouseProduct::query()
            ->with(['warehouse:id,code,name', 'product:id,sku,name'])
            ->get()
            ->map(fn (WarehouseProduct $warehouseProduct): array => [
                'warehouse_code' => $warehouseProduct->warehouse?->code,
                'warehouse_name' => $warehouseProduct->warehouse?->name,
                'product_sku'    => $warehouseProduct->product?->sku,
                'product_name'   => $warehouseProduct->product?->name,
                'qty_on_hand'    => (float) $warehouseProduct->qty_on_hand,
                'wac_amount'     => (float) $warehouseProduct->wac_amount,
                'total_value'    => round((float) $warehouseProduct->qty_on_hand * (float) $warehouseProduct->wac_amount, 2),
                'currency'       => $currency,
                'snapshot_date'  => $period->end_date,
            ])
            ->all();

        return [
            'rows'                   => $rows,
            'inventory_account_code' => (string) config('inventory.erp.accounting.accounts.inventory_asset', '1300'),
        ];
    }
}

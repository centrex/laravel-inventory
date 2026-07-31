<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Models\{Warehouse, WarehouseProduct};
use Centrex\TallUi\Concerns\CachesData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\{Blade, Gate};
use Livewire\Component;

/**
 * Split out of the inventory dashboard's Overview tab — weighted-average stock value and
 * net saleable stock (on-hand − reserved) by warehouse. Unlike the sales cards on this
 * tab, this data isn't scoped to the current user (warehouse stock is company-wide), so
 * the cache key doesn't need to carry auth()->id().
 *
 * One grouped query instead of 2 per warehouse (getStockValue()/getNetSaleableStock()
 * called per row) — those methods stay as-is for their existing single-warehouse callers.
 * Aliases are deliberately NOT "stock_value"/"net_saleable_stock": WarehouseProduct
 * defines a real getNetSaleableStockAttribute() accessor, and Eloquent calls that
 * accessor instead of returning a raw-selected column of the same name — which then
 * throws MissingAttributeException reaching for qty_on_hand/qty_reserved, columns
 * this aggregate query never selects.
 */
class InventoryWarehouseStockCard extends Component
{
    use CachesData;

    public function mount(): void
    {
        Gate::authorize('inventory.master-data.view');
        $this->cacheTtl = 300;
    }

    public function warehouseStock(): array
    {
        return $this->rememberCache(
            $this->cacheKey('inventory', 'warehouse-stock-card'),
            fn (): array => $this->buildWarehouseStock(),
        );
    }

    public function placeholder(): string
    {
        return Blade::render(<<<'BLADE'
            <div role="status" aria-label="Loading warehouse stock" class="animate-pulse mb-6">
                <div class="rounded-2xl border border-base-200 bg-base-100 p-5">
                    <div class="h-4 w-40 rounded bg-base-300 mb-5"></div>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 mb-5">
                        <div class="h-20 rounded-xl bg-base-200"></div>
                        <div class="h-20 rounded-xl bg-base-200"></div>
                    </div>
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                        @for ($i = 0; $i < 4; $i++)
                            <div class="h-28 rounded-xl bg-base-200"></div>
                        @endfor
                    </div>
                </div>
            </div>
            BLADE);
    }

    public function render(): View
    {
        $data = $this->warehouseStock();

        return view('inventory::livewire.transactions.inventory-warehouse-stock-card', [
            'warehouseStockValues'  => $data['warehouses'],
            'totalStockValue'       => $data['total_stock_value'],
            'totalNetSaleableStock' => $data['total_net_saleable_stock'],
        ]);
    }

    private function buildWarehouseStock(): array
    {
        $warehouseStockTotals = WarehouseProduct::query()
            ->selectRaw('warehouse_id, SUM(qty_on_hand * wac_amount) as agg_stock_value, SUM(qty_on_hand - qty_reserved) as agg_net_saleable_stock')
            ->groupBy('warehouse_id')
            ->get()
            ->keyBy('warehouse_id');

        $warehouses = Warehouse::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Warehouse $warehouse): array => [
                'id'                 => $warehouse->id,
                'name'               => $warehouse->name,
                'currency'           => $warehouse->currency,
                'stock_value'        => (float) ($warehouseStockTotals->get($warehouse->id)?->agg_stock_value ?? 0),
                'net_saleable_stock' => (float) ($warehouseStockTotals->get($warehouse->id)?->agg_net_saleable_stock ?? 0),
            ]);

        return [
            // ->all(), not the Collection itself — the cache store serializes this whole
            // array with PHP's native serialize(), and a Collection surviving into that
            // blob can come back wrong (incomplete object, or worse) if its class isn't
            // autoloaded yet on the next request, so a later access throws deep inside
            // the view with no obvious link back to the cache.
            'warehouses'               => $warehouses->all(),
            'total_stock_value'        => $warehouses->sum('stock_value'),
            'total_net_saleable_stock' => $warehouses->sum('net_saleable_stock'),
        ];
    }
}

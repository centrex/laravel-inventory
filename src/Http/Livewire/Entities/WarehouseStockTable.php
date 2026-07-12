<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Entities;

use Centrex\Inventory\Models\{Warehouse, WarehouseProduct};
use Centrex\TallUi\Concerns\WithFilters;
use Centrex\TallUi\DataTable\{Column, Filter};
use Centrex\TallUi\Livewire\DataTable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

class WarehouseStockTable extends DataTable
{
    use WithFilters;

    public string $defaultSortBy = 'warehouse_id';

    public string $defaultSortDirection = 'asc';

    /** Re-render after the parent page deletes a stock record. */
    #[On('warehouse-stock-table:refresh')]
    public function refreshTable(): void {}

    public function columns(): array
    {
        return [
            Column::make('Warehouse', 'warehouse.name')->relation('warehouse')->sortable(),
            Column::make('Product', 'product.name')->relation('product')->searchable()
                ->view('inventory::livewire.partials.warehouse-stock-table.product'),
            Column::make('SKU', 'sku')->searchable()->sortable(),
            Column::make('On Hand', 'qty_on_hand')->format('decimal:2')->sortable()->summable(),
            Column::make('Reserved', 'qty_reserved')->format('decimal:2')->sortable()->summable(),
            Column::make('In Transit', 'qty_in_transit')->format('decimal:2')->sortable()->summable(),
            Column::make('WAC', 'wac_amount')->format('decimal:4')->sortable(),
            Column::make('Reorder Point', 'reorder_point')->format('decimal:2')->sortable()->hideOnMobile(),
            Column::make('Actions')
                ->view('inventory::livewire.partials.warehouse-stock-table.actions'),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::select('Warehouse', 'warehouse_id', Warehouse::query()->orderBy('name')->pluck('name', 'id')->all()),
        ];
    }

    public function query(): Builder
    {
        return WarehouseProduct::query()
            ->with(['warehouse', 'product', 'variant'])
            ->where(function (Builder $builder): void {
                $builder->where('qty_on_hand', '>', 0)
                    ->orWhere('qty_reserved', '>', 0)
                    ->orWhere('qty_in_transit', '>', 0);
            });
    }

    protected function applySearchConstraint(Builder $query, string $column, string $search): void
    {
        if ($column === 'product.name') {
            $query->orWhereHas('product', function (Builder $q) use ($search): void {
                $q->where('sku', 'like', '%' . $search . '%')
                    ->orWhere('name', 'like', '%' . $search . '%');
            })->orWhereHas('variant', function (Builder $q) use ($search): void {
                $q->where('sku', 'like', '%' . $search . '%')
                    ->orWhere('name', 'like', '%' . $search . '%');
            });

            return;
        }

        parent::applySearchConstraint($query, $column, $search);
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Entities;

use Centrex\Inventory\Enums\PriceTierCode;
use Centrex\Inventory\Models\{ProductPrice, Warehouse, WarehouseProduct};
use Centrex\TallUi\Concerns\WithFilters;
use Centrex\TallUi\DataTable\{Column, Filter};
use Centrex\TallUi\Livewire\DataTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProductPriceTable extends DataTable
{
    use WithFilters;

    public string $defaultSortBy = 'warehouse_id';

    public string $defaultSortDirection = 'asc';

    /**
     * Lazily computed, memoized per request the first time a price-tier cell is
     * rendered — batches one query for the whole page instead of one per cell.
     *
     * @var Collection<string, Collection<int, ProductPrice>>|null
     */
    protected ?Collection $pricesByStockKey = null;

    public function columns(): array
    {
        $columns = [
            Column::make('Product', 'product.name')->relation('product')->searchable()
                ->view('inventory::livewire.partials.product-price-table.product'),
            Column::make('Warehouse', 'warehouse.name')->relation('warehouse')->sortable(),
        ];

        foreach (PriceTierCode::ordered() as $tier) {
            $columns[] = Column::make($tier->label(), 'tier:' . $tier->value)
                ->align('right')
                ->excludeFromExport()
                ->view('inventory::livewire.partials.product-price-table.price');
        }

        $columns[] = Column::make('Actions')
            ->view('inventory::livewire.partials.product-price-table.actions');

        return $columns;
    }

    public function filters(): array
    {
        return [
            Filter::select('Warehouse', 'warehouse_id', Warehouse::query()->orderBy('name')->pluck('name', 'id')->all()),
        ];
    }

    public function query(): Builder
    {
        return WarehouseProduct::query()->with(['product', 'warehouse']);
    }

    public function renderHtmlColumn(array $column, mixed $row): string
    {
        if (is_string($column['key']) && str_starts_with($column['key'], 'tier:')) {
            $tierValue = substr($column['key'], 5);
            $price = $this->pricesFor($row)->firstWhere('price_tier_code', $tierValue);

            return view('inventory::livewire.partials.product-price-table.price', ['price' => $price])->render();
        }

        return parent::renderHtmlColumn($column, $row);
    }

    /**
     * @return Collection<int, ProductPrice>
     */
    protected function pricesFor(WarehouseProduct $stock): Collection
    {
        return $this->allPrices()->get($stock->product_id . ':' . $stock->warehouse_id, collect());
    }

    /**
     * @return Collection<string, Collection<int, ProductPrice>>
     */
    protected function allPrices(): Collection
    {
        if ($this->pricesByStockKey !== null) {
            return $this->pricesByStockKey;
        }

        $rows = collect($this->getRows()->items());
        $productIds = $rows->pluck('product_id')->unique();
        $warehouseIds = $rows->pluck('warehouse_id')->unique();

        if ($productIds->isEmpty()) {
            return $this->pricesByStockKey = collect();
        }

        return $this->pricesByStockKey = ProductPrice::query()
            ->whereIn('product_id', $productIds)
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('is_damaged', false)
            ->get()
            ->groupBy(fn (ProductPrice $price): string => $price->product_id . ':' . $price->warehouse_id);
    }
}

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
use Livewire\Attributes\On;

class WarehouseStockTable extends DataTable
{
    use WithFilters;

    public string $defaultSortBy = 'warehouse_id';

    public string $defaultSortDirection = 'asc';

    /**
     * Lazily computed, memoized per request the first time a B2B Retail cell is
     * rendered — batches one query for the whole page instead of one per row.
     *
     * @var Collection<int, ProductPrice>|null
     */
    protected ?Collection $b2bRetailPrices = null;

    /** Re-render after the parent page deletes a stock record. */
    #[On('warehouse-stock-table:refresh')]
    public function refreshTable(): void {}

    public function columns(): array
    {
        return [
            Column::make('Warehouse', 'warehouse.name')->relation('warehouse')->sortable(),
            Column::make('Product', 'product.name')->relation('product')->searchable()
                ->view('inventory::livewire.partials.warehouse-stock-table.product'),
            Column::make('On Hand', 'qty_on_hand')->format('decimal:2')->sortable()->summable(),
            Column::make('Reserved', 'qty_reserved')->format('decimal:2')->sortable()->summable(),
            Column::make('In Transit', 'qty_in_transit')->format('decimal:2')->sortable()->summable(),
            Column::make('WAC', 'wac_amount')->format('decimal:4')->sortable(),
            Column::make('B2B Retail', 'b2b_retail_price')->align('right')->excludeFromExport()
                ->view('inventory::livewire.partials.product-price-table.price'),
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

    public function renderHtmlColumn(array $column, mixed $row): string
    {
        if (($column['key'] ?? null) === 'b2b_retail_price' && $row instanceof WarehouseProduct) {
            return view('inventory::livewire.partials.product-price-table.price', [
                'price' => $this->b2bRetailPriceFor($row),
            ])->render();
        }

        return parent::renderHtmlColumn($column, $row);
    }

    protected function applySearchConstraint(Builder $query, string $column, string $search): void
    {
        if (in_array($column, ['product.name', 'sku'], true)) {
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

    /**
     * Effective B2B Retail price for a stock row, following resolvePrice()'s
     * priority: variant+warehouse → variant+global → warehouse → global.
     */
    protected function b2bRetailPriceFor(WarehouseProduct $stock): ?ProductPrice
    {
        $candidates = $this->allB2bRetailPrices()
            ->filter(fn (ProductPrice $price): bool => $price->product_id === $stock->product_id
                && ($price->warehouse_id === null || $price->warehouse_id === $stock->warehouse_id)
                && ($price->variant_id === null || $price->variant_id === $stock->variant_id));

        if ($stock->variant_id !== null) {
            $match = $candidates->first(fn (ProductPrice $price): bool => $price->variant_id === $stock->variant_id && $price->warehouse_id === $stock->warehouse_id)
                ?? $candidates->first(fn (ProductPrice $price): bool => $price->variant_id === $stock->variant_id && $price->warehouse_id === null);

            if ($match !== null) {
                return $match;
            }
        }

        return $candidates->first(fn (ProductPrice $price): bool => $price->variant_id === null && $price->warehouse_id === $stock->warehouse_id)
            ?? $candidates->first(fn (ProductPrice $price): bool => $price->variant_id === null && $price->warehouse_id === null);
    }

    /**
     * @return Collection<int, ProductPrice>
     */
    protected function allB2bRetailPrices(): Collection
    {
        if ($this->b2bRetailPrices !== null) {
            return $this->b2bRetailPrices;
        }

        $rows = collect($this->getRows()->items());
        $productIds = $rows->pluck('product_id')->unique();

        if ($productIds->isEmpty()) {
            return $this->b2bRetailPrices = collect();
        }

        $today = now()->toDateString();

        return $this->b2bRetailPrices = ProductPrice::query()
            ->whereIn('product_id', $productIds)
            ->where(function (Builder $builder) use ($rows): void {
                $builder->whereNull('warehouse_id')
                    ->orWhereIn('warehouse_id', $rows->pluck('warehouse_id')->unique());
            })
            ->where('price_tier_code', PriceTierCode::B2B_RETAIL->value)
            ->where('is_active', true)
            ->where('is_damaged', false)
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->latest()
            ->get();
    }
}

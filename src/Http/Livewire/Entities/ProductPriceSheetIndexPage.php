<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Entities;

use Centrex\Inventory\Enums\PriceTierCode;
use Centrex\Inventory\Models\{ProductPrice, Warehouse, WarehouseProduct};
use Illuminate\Contracts\View\View;
use Livewire\Attributes\{Layout, Url};
use Livewire\{Component, WithPagination};

#[Layout('layouts.app')]
class ProductPriceSheetIndexPage extends Component
{
    use WithPagination;

    #[Url(as: 'search', except: '')]
    public string $search = '';

    public ?int $filterWarehouseId = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilterWarehouseId(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $query = WarehouseProduct::query()
            ->with(['product', 'warehouse'])
            ->orderBy('warehouse_id')
            ->orderBy('product_id');

        if ($this->filterWarehouseId !== null) {
            $query->where('warehouse_id', $this->filterWarehouseId);
        }

        if ($this->search !== '') {
            $search = $this->search;
            $query->whereHas('product', fn ($q) => $q
                ->where('name', 'like', '%' . $search . '%')
                ->orWhere('sku', 'like', '%' . $search . '%'));
        }

        // One row per (product, warehouse) that's actually stocked there — this bounds the
        // list to viable combinations instead of every product times every warehouse.
        $stockRows = $query->paginate(15);
        $tiers = PriceTierCode::ordered();

        $productIds = $stockRows->pluck('product_id')->unique();
        $warehouseIds = $stockRows->pluck('warehouse_id')->unique();

        $prices = ProductPrice::query()
            ->whereIn('product_id', $productIds)
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('is_damaged', false)
            ->get()
            ->groupBy(fn (ProductPrice $price): string => $price->product_id . ':' . $price->warehouse_id);

        $rows = $stockRows->getCollection()->map(fn (WarehouseProduct $stock): array => [
            'product'   => $stock->product,
            'warehouse' => $stock->warehouse,
            'prices'    => collect($tiers)->mapWithKeys(
                fn (PriceTierCode $tier) => [
                    $tier->value => $prices
                        ->get($stock->product_id . ':' . $stock->warehouse_id, collect())
                        ->firstWhere('price_tier_code', $tier->value),
                ],
            ),
        ]);

        return view('inventory::livewire.entities.product-price-sheet-index', [
            'rows'       => $rows,
            'stockRows'  => $stockRows,
            'tiers'      => $tiers,
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}

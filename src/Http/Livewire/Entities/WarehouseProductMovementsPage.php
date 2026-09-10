<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Entities;

use Centrex\Inventory\Models\{StockMovement, WarehouseProduct};
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\{Layout, Url};
use Livewire\{Component, WithPagination};

/**
 * Read-only inv_stock_movements ledger for a single (warehouse, product, variant) row — the
 * append-only audit trail every GRN receipt, sale fulfillment, transfer, return, and adjustment
 * writes alongside its qty_before/qty_after. There was previously no UI for this at all (only
 * the OwenIt field-diff audit trail on the stock row itself, and a raw API endpoint), so a
 * warehouse-products/{id} balance had no way to be explained from the back office.
 */
#[Layout('layouts.app')]
class WarehouseProductMovementsPage extends Component
{
    use WithPagination;

    public WarehouseProduct $stock;

    #[Url(as: 'from', except: '')]
    public string $from = '';

    #[Url(as: 'to', except: '')]
    public string $to = '';

    public function mount(int $recordId): void
    {
        Gate::authorize('inventory.reports.view');

        $this->stock = WarehouseProduct::with(['warehouse', 'product', 'variant'])->findOrFail($recordId);
    }

    public function updatingFrom(): void
    {
        $this->resetPage();
    }

    public function updatingTo(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->from = '';
        $this->to = '';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, StockMovement> */
    protected function movements(): LengthAwarePaginator
    {
        return StockMovement::query()
            ->where('warehouse_id', $this->stock->warehouse_id)
            ->where('product_id', $this->stock->product_id)
            ->where('variant_id', $this->stock->variant_id)
            ->when($this->from !== '', fn ($q) => $q->where('moved_at', '>=', $this->from))
            ->when($this->to !== '', fn ($q) => $q->where('moved_at', '<=', $this->to))
            ->latest('moved_at')
            ->latest('id')
            ->paginate(25);
    }

    public function render(): View
    {
        return view('inventory::livewire.entities.warehouse-product-movements', [
            'movements' => $this->movements(),
        ]);
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Enums\ShipmentStatus;
use Centrex\Inventory\Models\Shipment;
use Centrex\Inventory\Support\ShipmentExcelExporter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\{Component, WithPagination};
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app')]
class ShipmentIndexPage extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function downloadExcel(): StreamedResponse
    {
        $shipments = $this->filteredQuery()
            ->with(['fromWarehouse', 'toWarehouse', 'boxes.items.product', 'boxes.items.variant'])
            ->get();

        return ShipmentExcelExporter::download($shipments, 'shipments-' . now()->format('Ymd-His') . '.xls');
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.shipment-index', [
            'shipments'     => $this->filteredQuery()->paginate(15),
            'statusOptions' => collect(ShipmentStatus::cases())
                ->mapWithKeys(fn (ShipmentStatus $status): array => [$status->value => $status->label()])
                ->all(),
        ]);
    }

    private function filteredQuery(): Builder
    {
        $query = Shipment::query()
            ->with(['fromWarehouse', 'toWarehouse'])
            ->withCount('boxes')
            ->latest('created_at')
            ->latest('id');

        if ($this->search !== '') {
            $search = $this->search;
            $query->where(function ($builder) use ($search): void {
                $builder->where('shipment_number', 'like', '%' . $search . '%')
                    ->orWhere('notes', 'like', '%' . $search . '%')
                    ->orWhereHas('fromWarehouse', fn ($warehouseQuery) => $warehouseQuery->where('name', 'like', '%' . $search . '%'))
                    ->orWhereHas('toWarehouse', fn ($warehouseQuery) => $warehouseQuery->where('name', 'like', '%' . $search . '%'));
            });
        }

        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        return $query;
    }
}

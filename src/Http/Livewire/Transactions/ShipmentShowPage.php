<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Exceptions\InsufficientStockException;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\Shipment;
use Centrex\Inventory\Support\ShipmentExcelExporter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app')]
class ShipmentShowPage extends Component
{
    public Shipment $record;

    public bool $showReceiveModal = false;

    public array $receiveQtys = [];

    public function mount(int $recordId): void
    {
        $this->record = Shipment::query()
            ->with($this->relations())
            ->findOrFail($recordId);
    }

    public function dispatch_shipment(): void
    {
        Gate::authorize('inventory.transfers.dispatch');

        try {
            $this->record = app(Inventory::class)->dispatchInterWarehouseShipment((int) $this->record->getKey());
            $this->dispatch('notify', type: 'success', message: "Shipment {$this->record->shipment_number} dispatched.");
        } catch (InsufficientStockException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function openReceiveModal(): void
    {
        $this->receiveQtys = [];

        foreach ($this->record->items as $item) {
            $remaining = max(0.0, (float) $item->qty_sent - (float) $item->qty_received);
            $this->receiveQtys[$item->id] = $remaining;
        }

        $this->showReceiveModal = true;
    }

    public function receivePartial(): void
    {
        Gate::authorize('inventory.transfers.receive');

        $qtys = collect($this->receiveQtys)
            ->filter(fn ($v) => (float) $v > 0)
            ->map(fn ($v) => (float) $v)
            ->all();

        $this->record = app(Inventory::class)->receiveInterWarehouseShipment((int) $this->record->getKey(), $qtys);
        $this->showReceiveModal = false;
        $this->dispatch('notify', type: 'success', message: "Shipment {$this->record->shipment_number} updated.");
    }

    public function receiveAll(): void
    {
        Gate::authorize('inventory.transfers.receive');

        $this->record = app(Inventory::class)->receiveInterWarehouseShipment((int) $this->record->getKey());
        $this->dispatch('notify', type: 'success', message: "Shipment {$this->record->shipment_number} received.");
    }

    public function downloadExcel(): StreamedResponse
    {
        $shipment = $this->refreshRecord();

        return ShipmentExcelExporter::download(collect([$shipment]), $this->record->shipment_number . '-boxes.xls');
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.shipment-show', [
            'record'      => $this->refreshRecord(),
            'canDispatch' => Gate::allows('inventory.transfers.dispatch'),
            'canReceive'  => Gate::allows('inventory.transfers.receive'),
        ]);
    }

    private function refreshRecord(): Shipment
    {
        $this->record = $this->record->fresh($this->relations());

        return $this->record;
    }

    private function relations(): array
    {
        return [
            'fromWarehouse',
            'toWarehouse',
            'items.product',
            'items.variant',
            'boxes.items.product',
            'boxes.items.variant',
        ];
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Models\Shipment;
use Centrex\Inventory\Support\ShipmentExcelExporter;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app')]
class ShipmentShowPage extends Component
{
    public Shipment $record;

    public function mount(int $recordId): void
    {
        $this->record = Shipment::query()
            ->with($this->relations())
            ->findOrFail($recordId);
    }

    public function downloadExcel(): StreamedResponse
    {
        $shipment = $this->refreshRecord();

        return ShipmentExcelExporter::download(collect([$shipment]), $this->record->shipment_number . '-boxes.xls');
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.shipment-show', [
            'record' => $this->refreshRecord(),
        ]);
    }

    private function refreshRecord(): Shipment
    {
        $this->record = $this->record->fresh($this->relations());

        return $this->record;
    }

    /**
     * @return array<int, string>
     */
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

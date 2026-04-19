<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Product, Warehouse};
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class TransferFormPage extends Component
{
    public ?int $from_warehouse_id = null;

    public ?int $to_warehouse_id = null;

    public float $shipping_rate_per_kg = 0;

    public string $notes = '';

    public array $boxes = [];

    public function mount(): void
    {
        $this->boxes = [$this->blankBox()];
    }

    public function addBox(): void
    {
        $this->boxes[] = $this->blankBox();
    }

    public function removeBox(int $index): void
    {
        unset($this->boxes[$index]);
        $this->boxes = array_values($this->boxes);
    }

    public function addItem(int $boxIndex): void
    {
        $this->boxes[$boxIndex]['items'][] = $this->blankItem();
    }

    public function removeItem(int $boxIndex, int $itemIndex): void
    {
        unset($this->boxes[$boxIndex]['items'][$itemIndex]);
        $this->boxes[$boxIndex]['items'] = array_values($this->boxes[$boxIndex]['items']);
    }

    public function save(): \Illuminate\Http\RedirectResponse
    {
        $validated = $this->validate([
            'from_warehouse_id'    => ['required', 'integer'],
            'to_warehouse_id'      => ['required', 'integer', 'different:from_warehouse_id'],
            'shipping_rate_per_kg' => ['nullable', 'numeric', 'min:0'],
            'notes'                => ['nullable', 'string'],
            'boxes'                       => ['required', 'array', 'min:1'],
            'boxes.*.box_code'            => ['nullable', 'string', 'max:50'],
            'boxes.*.measured_weight_kg'  => ['required', 'numeric', 'gt:0'],
            'boxes.*.notes'               => ['nullable', 'string'],
            'boxes.*.items'               => ['required', 'array', 'min:1'],
            'boxes.*.items.*.product_id'  => ['required', 'integer'],
            'boxes.*.items.*.qty_sent'    => ['required', 'numeric', 'gt:0'],
            'boxes.*.items.*.notes'       => ['nullable', 'string'],
        ]);

        $transfer = app(Inventory::class)->createTransfer($validated);
        session()->flash('inventory.status', "Transfer {$transfer->transfer_number} created.");

        return redirect()->route('inventory.transfers.create');
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.transfer-form', [
            'warehouses' => Warehouse::query()->orderBy('name')->get(),
            'products'   => Product::query()->orderBy('name')->get(),
        ]);
    }

    private function blankBox(): array
    {
        return [
            'box_code'           => null,
            'measured_weight_kg' => 0,
            'notes'              => '',
            'items'              => [$this->blankItem()],
        ];
    }

    private function blankItem(): array
    {
        return [
            'product_id' => null,
            'qty_sent'   => 1,
            'notes'      => '',
        ];
    }
}

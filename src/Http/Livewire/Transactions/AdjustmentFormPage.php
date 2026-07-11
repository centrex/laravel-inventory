<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Enums\AdjustmentReason;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Product, Warehouse};
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class AdjustmentFormPage extends Component
{
    public ?int $warehouse_id = null;

    public string $reason = 'cycle_count';

    public ?string $adjusted_at = null;

    public string $notes = '';

    public array $items = [];

    public function mount(): void
    {
        $this->reason = AdjustmentReason::CYCLE_COUNT->value;
        $this->adjusted_at = now()->toDateString();
        $this->items = [$this->blankItem()];
    }

    public function addItem(): void
    {
        $this->items[] = $this->blankItem();
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function save()
    {
        $validated = $this->validate([
            'warehouse_id'       => ['required', 'integer'],
            'reason'             => ['required', 'string'],
            'adjusted_at'        => ['nullable', 'date'],
            'notes'              => ['nullable', 'string'],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.qty_actual' => ['required', 'numeric'],
            'items.*.notes'      => ['nullable', 'string'],
        ]);

        $adjustment = app(Inventory::class)->createAdjustment($validated);
        $this->dispatch('notify', type: 'success', message: "Adjustment {$adjustment->adjustment_number} created.");

        return redirect()->route('inventory.adjustments.create');
    }

    /** Clear the previously selected variant whenever the product for a row changes. */
    public function updatedItems(mixed $value, string $key): void
    {
        if (str_ends_with($key, '.product_id')) {
            $index = (int) explode('.', $key)[0];
            $this->items[$index]['variant_id'] = null;
        }
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.adjustment-form', [
            'warehouses' => Warehouse::query()->orderBy('name')->get(),
            'products'   => Product::query()->with('variants')->orderBy('name')->get(),
            'reasons'    => AdjustmentReason::cases(),
        ]);
    }

    private function blankItem(): array
    {
        return [
            'product_id' => null,
            'variant_id' => null,
            'qty_actual' => 0,
            'notes'      => '',
        ];
    }
}

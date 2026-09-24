<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Enums\AdjustmentReason;
use Centrex\Inventory\Http\Livewire\Transactions\Concerns\GuardsAgainstDuplicateSubmission;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Product, Warehouse};
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class AdjustmentFormPage extends Component
{
    use GuardsAgainstDuplicateSubmission;

    public ?int $warehouse_id = null;

    public string $reason = 'cycle_count';

    public ?string $adjusted_at = null;

    public string $notes = '';

    public array $items = [];

    public function mount(): void
    {
        $this->initializeFormToken();
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

        return $this->onceForThisSubmission('inventory.adjustment.create', function () use ($validated) {
            $adjustment = app(Inventory::class)->createAdjustment($validated);
            app(Inventory::class)->postAdjustment((int) $adjustment->getKey());
            $this->dispatch('notify', type: 'success', message: "Adjustment {$adjustment->adjustment_number} posted.");

            return redirect()->route('inventory.adjustments.show', ['recordId' => $adjustment->getKey()]);
        });
    }

    /** Clear the previously selected variant whenever the product for a row changes. */
    public function updatedItems(mixed $value, ?string $key): void
    {
        if ($key === null) {
            return;
        }

        if (str_ends_with($key, '.product_id')) {
            $index = (int) explode('.', $key)[0];
            $this->items[$index]['variant_id'] = null;
        }
    }

    public function render(): View
    {
        // Only the products actually referenced by the current rows are loaded. This used to
        // fetch every product with every variant on each render — and since render() re-runs
        // on every Livewire round-trip, and the blade repeats the whole list as <option> tags
        // once per adjustment row, the cost was (products x rows) on each keystroke.
        $selectedProductIds = collect($this->items)
            ->pluck('product_id')
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $products = $selectedProductIds->isEmpty()
            ? collect()
            : Product::query()->with('variants')->whereIn('id', $selectedProductIds)->orderBy('name')->get();

        return view('inventory::livewire.transactions.adjustment-form', [
            'warehouses'             => Warehouse::query()->orderBy('name')->get(['id', 'name']),
            'products'               => $products,
            'selectedProductOptions' => $products->mapWithKeys(
                static fn (Product $product): array => [
                    $product->id => [
                        'label'    => (string) $product->name,
                        'sublabel' => filled($product->sku) ? (string) $product->sku : null,
                    ],
                ],
            )->all(),
            'reasons' => AdjustmentReason::cases(),
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

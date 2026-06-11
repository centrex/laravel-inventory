<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Product, Warehouse, WarehouseProduct};
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ShipmentFormPage extends Component
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

    public function updatedFromWarehouseId(): void
    {
        $this->boxes = [$this->blankBox()];
        $this->resetErrorBag();
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

    public function save()
    {
        $validated = $this->validate([
            'from_warehouse_id'          => ['required', 'integer'],
            'to_warehouse_id'            => ['required', 'integer', 'different:from_warehouse_id'],
            'shipping_rate_per_kg'       => ['nullable', 'numeric', 'min:0'],
            'notes'                      => ['nullable', 'string'],
            'boxes'                      => ['required', 'array', 'min:1'],
            'boxes.*.box_code'           => ['nullable', 'string', 'max:50'],
            'boxes.*.measured_weight_kg' => ['required', 'numeric', 'gt:0'],
            'boxes.*.notes'              => ['nullable', 'string'],
            'boxes.*.items'              => ['required', 'array', 'min:1'],
            'boxes.*.items.*.product_id' => ['required', 'integer'],
            'boxes.*.items.*.qty_sent'   => ['required', 'numeric', 'gt:0'],
            'boxes.*.items.*.notes'      => ['nullable', 'string'],
        ]);

        $this->assertStockAvailability($validated['boxes']);

        $shipment = app(Inventory::class)->createInterWarehouseShipment($validated);
        $this->dispatch('notify', type: 'success', message: "Shipment {$shipment->shipment_number} created.");

        return redirect()->route('inventory.shipments.show', ['recordId' => $shipment->getKey()]);
    }

    public function render(): View
    {
        $selectedProductIds = collect($this->boxes)
            ->flatMap(fn (array $box): array => collect($box['items'] ?? [])->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->all())
            ->all();

        $availableStock = $this->from_warehouse_id
            ? WarehouseProduct::query()
                ->where('warehouse_id', $this->from_warehouse_id)
                ->whereRaw('(qty_on_hand - qty_reserved) > 0')
                ->get()
                ->groupBy('product_id')
                ->map(fn ($rows) => (float) $rows->sum(fn ($wp) => max(0.0, (float) $wp->qty_on_hand - (float) $wp->qty_reserved)))
            : collect();

        $availableProductIds = $availableStock->keys()->all();
        $products = Product::query()
            ->when(
                $this->from_warehouse_id,
                fn ($query) => $query->where(function ($builder) use ($availableProductIds, $selectedProductIds): void {
                    $builder->whereIn('id', $availableProductIds);

                    if ($selectedProductIds !== []) {
                        $builder->orWhereIn('id', $selectedProductIds);
                    }
                }),
            )
            ->orderBy('name')
            ->get();

        $productsJson = $products->map(fn (Product $p): array => [
            'id'        => (int) $p->getKey(),
            'name'      => (string) $p->name,
            'sku'       => (string) ($p->sku ?? ''),
            'barcode'   => (string) ($p->barcode ?? ''),
            'weight_kg' => $p->weight_kg !== null ? (float) $p->weight_kg : null,
            'available' => round((float) ($availableStock->get($p->getKey()) ?? 0), 4),
        ])->values()->all();

        return view('inventory::livewire.transactions.shipment-form', [
            'warehouses'     => Warehouse::query()->orderBy('name')->get(),
            'products'       => $products,
            'productsJson'   => $productsJson,
            'availableStock' => $availableStock,
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

    private function assertStockAvailability(array $boxes): void
    {
        if (!$this->from_warehouse_id) {
            return;
        }

        $requested = [];

        foreach ($boxes as $box) {
            foreach ($box['items'] as $item) {
                $productId = (int) $item['product_id'];
                $requested[$productId] = ($requested[$productId] ?? 0) + round((float) $item['qty_sent'], 4);
            }
        }

        $stock = WarehouseProduct::query()
            ->where('warehouse_id', $this->from_warehouse_id)
            ->whereIn('product_id', array_keys($requested))
            ->get()
            ->groupBy('product_id')
            ->map(fn ($rows) => (float) $rows->sum(fn ($wp) => max(0.0, (float) $wp->qty_on_hand - (float) $wp->qty_reserved)));

        foreach ($requested as $productId => $qty) {
            $available = (float) ($stock->get($productId) ?? 0);

            if ($qty > $available + (float) config('inventory.qty_tolerance', 0.0001)) {
                $productName = Product::query()->find($productId)?->name ?? ('#' . $productId);

                throw ValidationException::withMessages([
                    'boxes' => "{$productName} only has {$available} available for shipment.",
                ]);
            }
        }
    }
}

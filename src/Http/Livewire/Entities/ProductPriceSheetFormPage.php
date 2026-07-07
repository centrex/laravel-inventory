<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Entities;

use Centrex\Inventory\Enums\PriceTierCode;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Product, ProductPrice, Warehouse};
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ProductPriceSheetFormPage extends Component
{
    // Not strictly `int` — Livewire's hydration sets properties directly (bypassing
    // mount()'s type coercion) on every subsequent request; a round-tripped string
    // value would otherwise throw a TypeError. Cast to int at each point of use.
    public int|string $recordId;

    public int|string $warehouseId;

    public Product $product;

    public Warehouse $warehouse;

    public ?int $variantId = null;

    /** @var array<string, array{price_amount: mixed, cost_price: mixed, moq: mixed, currency: mixed, is_active: bool}> */
    public array $tiers = [];

    public function mount(int $recordId, int $warehouseId): void
    {
        $this->recordId = $recordId;
        $this->warehouseId = $warehouseId;
        $this->product = Product::findOrFail($recordId);
        $this->warehouse = Warehouse::findOrFail($warehouseId);
        $this->variantId = $this->product->variants()->value('id');

        // Prices are always warehouse-scoped in this system (no global rows), so the sheet
        // edits this warehouse's undated row per tier for this product's own variant — the
        // same shape setPrice() upserts into when effective_from is left null.
        $existing = ProductPrice::query()
            ->where('product_id', $recordId)
            ->where('warehouse_id', $warehouseId)
            ->where('variant_id', $this->variantId)
            ->where('is_damaged', false)
            ->whereNull('effective_from')
            ->get()
            ->keyBy('price_tier_code');

        foreach (PriceTierCode::ordered() as $tier) {
            $price = $existing->get($tier->value);

            $this->tiers[$tier->value] = [
                'price_amount' => $price?->price_amount,
                'cost_price'   => $price?->cost_price,
                'moq'          => $price?->moq ?? 1,
                'currency'     => $price?->currency,
                'is_active'    => $price?->is_active ?? true,
            ];
        }
    }

    public function save()
    {
        $validated = validator($this->tiers, [
            '*.price_amount' => ['nullable', 'numeric', 'min:0'],
            '*.cost_price'   => ['nullable', 'numeric', 'min:0'],
            '*.moq'          => ['nullable', 'integer', 'min:1'],
            '*.currency'     => ['nullable', 'string', 'size:3'],
            '*.is_active'    => ['boolean'],
        ])->validate();

        foreach ($validated as $tierCode => $data) {
            if ($data['price_amount'] === null || $data['price_amount'] === '') {
                continue;
            }

            app(Inventory::class)->setPrice((int) $this->recordId, $tierCode, (float) $data['price_amount'], (int) $this->warehouseId, [
                'variant_id' => $this->variantId,
                'cost_price' => $data['cost_price'] !== null && $data['cost_price'] !== '' ? (float) $data['cost_price'] : null,
                'moq'        => $data['moq'] ?: 1,
                'currency'   => $data['currency'] ?: null,
                'is_active'  => (bool) ($data['is_active'] ?? true),
            ]);
        }

        $this->dispatch('notify', type: 'success', message: 'Prices updated for ' . $this->product->name . ' at ' . $this->warehouse->name . '.');

        return redirect()->route('inventory.entities.product-prices.index');
    }

    public function render(): View
    {
        return view('inventory::livewire.entities.product-price-sheet-form', [
            'tierOptions' => PriceTierCode::ordered(),
        ]);
    }
}

<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Transactions\SaleOrderFormPage;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Product, ProductPrice, Warehouse};
use Illuminate\Support\Facades\Gate;

function resolveLinePrice(int $warehouseId, int $productId, string $tierCode): ProductPrice
{
    Gate::define('inventory.sale-orders.create', fn ($user = null) => true);

    $page = new SaleOrderFormPage();
    $page->mount();
    $page->warehouse_id = $warehouseId;

    $method = new ReflectionMethod($page, 'resolvePriceForLine');
    $method->setAccessible(true);

    return $method->invoke($page, app(Inventory::class), $productId, $tierCode);
}

beforeEach(function (): void {
    $this->warehouse = Warehouse::create([
        'code' => 'W1', 'name' => 'Main', 'country_code' => 'BD', 'currency' => 'BDT', 'is_active' => true, 'is_default' => true,
    ]);
    $this->product = Product::create([
        'sku' => 'SKU-1', 'name' => 'Widget', 'unit' => 'pcs', 'is_active' => true, 'is_stockable' => true,
    ]);
});

it('uses the selected tier price when one exists', function (): void {
    ProductPrice::create(['product_id' => $this->product->id, 'price_tier_code' => 'b2b_wholesale', 'price_amount' => 40, 'price_local' => 40, 'is_active' => true, 'is_damaged' => false]);
    ProductPrice::create(['product_id' => $this->product->id, 'price_tier_code' => 'b2c_pos', 'price_amount' => 99, 'price_local' => 99, 'is_active' => true, 'is_damaged' => false]);

    $price = resolveLinePrice($this->warehouse->id, $this->product->id, 'b2b_wholesale');

    expect((float) $price->price_amount)->toBe(40.0);
});

it('never falls back to another tier when the selected tier has no price', function (): void {
    ProductPrice::create(['product_id' => $this->product->id, 'price_tier_code' => 'b2c_pos', 'price_amount' => 99, 'price_local' => 99, 'is_active' => true, 'is_damaged' => false]);

    $price = resolveLinePrice($this->warehouse->id, $this->product->id, 'b2b_wholesale');

    expect((float) $price->price_amount)->toBe(0.0);
});

it('falls back to the base tier when the selected tier has no price', function (): void {
    ProductPrice::create(['product_id' => $this->product->id, 'price_tier_code' => 'base', 'price_amount' => 55, 'price_local' => 55, 'is_active' => true, 'is_damaged' => false]);
    ProductPrice::create(['product_id' => $this->product->id, 'price_tier_code' => 'b2c_pos', 'price_amount' => 99, 'price_local' => 99, 'is_active' => true, 'is_damaged' => false]);

    $price = resolveLinePrice($this->warehouse->id, $this->product->id, 'b2b_wholesale');

    expect((float) $price->price_amount)->toBe(55.0);
});

it('ignores damaged-bin prices when resolving a line price', function (): void {
    ProductPrice::create(['product_id' => $this->product->id, 'price_tier_code' => 'b2b_wholesale', 'price_amount' => 20, 'price_local' => 20, 'is_active' => true, 'is_damaged' => true]);
    ProductPrice::create(['product_id' => $this->product->id, 'price_tier_code' => 'b2b_wholesale', 'price_amount' => 40, 'price_local' => 40, 'is_active' => true, 'is_damaged' => false]);

    $price = resolveLinePrice($this->warehouse->id, $this->product->id, 'b2b_wholesale');

    expect((float) $price->price_amount)->toBe(40.0);
});

it('uses the product meta default price as the last resort', function (): void {
    $this->product->update(['meta' => ['default_price' => 12.5]]);

    $price = resolveLinePrice($this->warehouse->id, $this->product->id, 'b2b_wholesale');

    expect((float) $price->price_amount)->toBe(12.5);
});

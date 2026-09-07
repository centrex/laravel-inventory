<?php

declare(strict_types = 1);

use Centrex\Inventory\Exceptions\PriceNotFoundException;
use Centrex\Inventory\Facades\Inventory;
use Centrex\Inventory\Models\{Product, ProductPrice, ProductVariant, Warehouse};

/**
 * createSaleOrder() resolves per-line prices via pickPrice() against a batch-prefetched
 * candidate collection instead of calling resolvePrice() (which hits the DB up to 4 times per
 * line) once per item. These lock in that pickPrice() picks the exact same price resolvePrice()
 * would for every fallback tier, so the batching in createSaleOrder() introduced in that fix
 * can't silently drift from resolvePrice()'s own behavior.
 */
beforeEach(function (): void {
    $this->warehouse = Warehouse::create([
        'code'     => 'PRC', 'name' => 'Pricing Warehouse', 'country_code' => 'BD',
        'currency' => 'BDT', 'is_active' => true, 'is_default' => true,
    ]);

    $this->product = Product::create([
        'sku' => 'PRC-1', 'name' => 'Priced Widget', 'unit' => 'pcs', 'is_active' => true, 'is_stockable' => true,
    ]);
});

function createOrderForPricingTest(Warehouse $warehouse, Product $product, ?int $variantId = null, bool $fromDamaged = false): float
{
    $so = Inventory::createSaleOrder([
        'warehouse_id' => $warehouse->id,
        'currency'     => 'BDT',
        'items'        => [[
            'product_id'   => $product->id,
            'variant_id'   => $variantId,
            'qty_ordered'  => 1,
            'from_damaged' => $fromDamaged,
        ]],
    ]);

    return (float) $so->items->first()->unit_price_amount;
}

it('prefers a variant+warehouse price over every other fallback', function (): void {
    $variant = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'PRC-1-V1', 'name' => 'Variant 1', 'is_active' => true]);

    ProductPrice::create(['product_id' => $this->product->id, 'variant_id' => $variant->id, 'warehouse_id' => $this->warehouse->id, 'price_tier_code' => 'b2b_retail', 'price_amount' => 10, 'price_local' => 10, 'is_active' => true, 'is_damaged' => false]);
    ProductPrice::create(['product_id' => $this->product->id, 'variant_id' => $variant->id, 'price_tier_code' => 'b2b_retail', 'price_amount' => 20, 'price_local' => 20, 'is_active' => true, 'is_damaged' => false]);
    ProductPrice::create(['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'price_tier_code' => 'b2b_retail', 'price_amount' => 30, 'price_local' => 30, 'is_active' => true, 'is_damaged' => false]);
    ProductPrice::create(['product_id' => $this->product->id, 'price_tier_code' => 'b2b_retail', 'price_amount' => 40, 'price_local' => 40, 'is_active' => true, 'is_damaged' => false]);

    expect(createOrderForPricingTest($this->warehouse, $this->product, $variant->id))->toBe(10.0);
});

it('falls back to variant+global when no variant+warehouse price exists', function (): void {
    $variant = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'PRC-1-V2', 'name' => 'Variant 2', 'is_active' => true]);

    ProductPrice::create(['product_id' => $this->product->id, 'variant_id' => $variant->id, 'price_tier_code' => 'b2b_retail', 'price_amount' => 20, 'price_local' => 20, 'is_active' => true, 'is_damaged' => false]);
    ProductPrice::create(['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'price_tier_code' => 'b2b_retail', 'price_amount' => 30, 'price_local' => 30, 'is_active' => true, 'is_damaged' => false]);
    ProductPrice::create(['product_id' => $this->product->id, 'price_tier_code' => 'b2b_retail', 'price_amount' => 40, 'price_local' => 40, 'is_active' => true, 'is_damaged' => false]);

    expect(createOrderForPricingTest($this->warehouse, $this->product, $variant->id))->toBe(20.0);
});

it('falls back to plain product+warehouse when the item has no variant', function (): void {
    ProductPrice::create(['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'price_tier_code' => 'b2b_retail', 'price_amount' => 30, 'price_local' => 30, 'is_active' => true, 'is_damaged' => false]);
    ProductPrice::create(['product_id' => $this->product->id, 'price_tier_code' => 'b2b_retail', 'price_amount' => 40, 'price_local' => 40, 'is_active' => true, 'is_damaged' => false]);

    expect(createOrderForPricingTest($this->warehouse, $this->product))->toBe(30.0);
});

it('falls back to the plain global price as the last resort', function (): void {
    ProductPrice::create(['product_id' => $this->product->id, 'price_tier_code' => 'b2b_retail', 'price_amount' => 40, 'price_local' => 40, 'is_active' => true, 'is_damaged' => false]);

    expect(createOrderForPricingTest($this->warehouse, $this->product))->toBe(40.0);
});

it('prefers a damaged price when from_damaged is requested but falls back to the regular price', function (): void {
    ProductPrice::create(['product_id' => $this->product->id, 'price_tier_code' => 'b2b_retail', 'price_amount' => 40, 'price_local' => 40, 'is_active' => true, 'is_damaged' => false]);

    expect(createOrderForPricingTest($this->warehouse, $this->product, fromDamaged: true))->toBe(40.0);

    ProductPrice::create(['product_id' => $this->product->id, 'price_tier_code' => 'b2b_retail', 'price_amount' => 15, 'price_local' => 15, 'is_active' => true, 'is_damaged' => true]);

    expect(createOrderForPricingTest($this->warehouse, $this->product, fromDamaged: true))->toBe(15.0);
});

it('never uses a damaged price for a non-damaged line', function (): void {
    ProductPrice::create(['product_id' => $this->product->id, 'price_tier_code' => 'b2b_retail', 'price_amount' => 15, 'price_local' => 15, 'is_active' => true, 'is_damaged' => true]);
    ProductPrice::create(['product_id' => $this->product->id, 'price_tier_code' => 'b2b_retail', 'price_amount' => 40, 'price_local' => 40, 'is_active' => true, 'is_damaged' => false]);

    expect(createOrderForPricingTest($this->warehouse, $this->product))->toBe(40.0);
});

it('resolves distinct correct prices for multiple products on the same order', function (): void {
    $productTwo = Product::create(['sku' => 'PRC-2', 'name' => 'Second Widget', 'unit' => 'pcs', 'is_active' => true, 'is_stockable' => true]);

    ProductPrice::create(['product_id' => $this->product->id, 'price_tier_code' => 'b2b_retail', 'price_amount' => 40, 'price_local' => 40, 'is_active' => true, 'is_damaged' => false]);
    ProductPrice::create(['product_id' => $productTwo->id, 'price_tier_code' => 'b2b_retail', 'price_amount' => 75, 'price_local' => 75, 'is_active' => true, 'is_damaged' => false]);

    $so = Inventory::createSaleOrder([
        'warehouse_id' => $this->warehouse->id,
        'currency'     => 'BDT',
        'items'        => [
            ['product_id' => $this->product->id, 'qty_ordered' => 1],
            ['product_id' => $productTwo->id, 'qty_ordered' => 1],
        ],
    ]);

    expect((float) $so->items[0]->unit_price_amount)->toBe(40.0)
        ->and((float) $so->items[1]->unit_price_amount)->toBe(75.0);
});

it('throws when no price exists anywhere for the product', function (): void {
    Inventory::createSaleOrder([
        'warehouse_id' => $this->warehouse->id,
        'currency'     => 'BDT',
        'items'        => [['product_id' => $this->product->id, 'qty_ordered' => 1]],
    ]);
})->throws(PriceNotFoundException::class);

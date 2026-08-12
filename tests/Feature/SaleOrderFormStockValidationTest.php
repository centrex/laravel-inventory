<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Transactions\SaleOrderFormPage;
use Centrex\Inventory\Models\{Customer, Product, SaleOrder, Warehouse, WarehouseProduct};
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

it('surfaces a visible error and blocks submission when qty_ordered exceeds available stock', function (): void {
    Gate::define('inventory.sale-orders.create', fn ($user = null) => true);
    Gate::define('inventory.sale-orders.approve-credit', fn ($user = null) => true);

    $warehouse = Warehouse::create([
        'code' => 'W-STOCK', 'name' => 'Stock Warehouse', 'country_code' => 'GB', 'currency' => 'BDT', 'is_active' => true,
    ]);
    $customer = Customer::create([
        'code'            => 'CUS-STOCK', 'name' => 'Stock Customer', 'currency' => 'BDT',
        'price_tier_code' => 'b2b_retail', 'is_active' => true,
    ]);
    $product = Product::create([
        'sku' => 'SKU-STOCK', 'name' => 'Widget', 'unit' => 'pcs', 'is_active' => true, 'is_stockable' => true,
    ]);
    WarehouseProduct::create([
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'qty_on_hand'  => 5, 'qty_reserved' => 0, 'qty_in_transit' => 0, 'wac_amount' => 10,
    ]);

    $component = Livewire::test(SaleOrderFormPage::class)
        ->set('warehouse_id', $warehouse->id)
        ->set('customer_id', $customer->id)
        ->set('items.0.product_id', $product->id)
        ->set('items.0.qty_ordered', 50) // only 5 in stock
        ->set('items.0.unit_price_local', 10)
        ->call('save');

    // Regression: assertStockAvailability() previously only keyed its exception under 'items',
    // which nothing on the form displayed — the submit was blocked with zero visible feedback.
    $component->assertHasErrors(['items', 'items.0.qty_ordered']);

    expect($component->errors()->first('items'))->toContain('available')
        ->and(SaleOrder::count())->toBe(0);
});

it('creates the order normally when qty_ordered is within available stock', function (): void {
    Gate::define('inventory.sale-orders.create', fn ($user = null) => true);
    Gate::define('inventory.sale-orders.approve-credit', fn ($user = null) => true);

    $warehouse = Warehouse::create([
        'code' => 'W-STOCK-2', 'name' => 'Stock Warehouse 2', 'country_code' => 'GB', 'currency' => 'BDT', 'is_active' => true,
    ]);
    $customer = Customer::create([
        'code'            => 'CUS-STOCK-2', 'name' => 'Stock Customer 2', 'currency' => 'BDT',
        'price_tier_code' => 'b2b_retail', 'is_active' => true,
    ]);
    $product = Product::create([
        'sku' => 'SKU-STOCK-2', 'name' => 'Widget 2', 'unit' => 'pcs', 'is_active' => true, 'is_stockable' => true,
    ]);
    WarehouseProduct::create([
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'qty_on_hand'  => 5, 'qty_reserved' => 0, 'qty_in_transit' => 0, 'wac_amount' => 10,
    ]);

    Livewire::test(SaleOrderFormPage::class)
        ->set('warehouse_id', $warehouse->id)
        ->set('customer_id', $customer->id)
        ->set('items.0.product_id', $product->id)
        ->set('items.0.qty_ordered', 5)
        ->set('items.0.unit_price_local', 10)
        ->call('save')
        ->assertHasNoErrors();

    expect(SaleOrder::count())->toBe(1);
});

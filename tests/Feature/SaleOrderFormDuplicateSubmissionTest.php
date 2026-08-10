<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Transactions\SaleOrderFormPage;
use Centrex\Inventory\Models\{Customer, Product, SaleOrder, Warehouse, WarehouseProduct};
use Illuminate\Support\Facades\{Cache, Gate};
use Livewire\Livewire;

function saleOrderCreateFixture(): array
{
    Gate::define('inventory.sale-orders.create', fn ($user = null) => true);
    Gate::define('inventory.sale-orders.approve-credit', fn ($user = null) => true);

    $warehouse = Warehouse::create([
        'code' => 'W-DUP', 'name' => 'Dup Warehouse', 'country_code' => 'GB', 'currency' => 'BDT', 'is_active' => true,
    ]);
    $customer = Customer::create([
        'code'            => 'CUS-DUP', 'name' => 'Dup Customer', 'currency' => 'BDT',
        'price_tier_code' => 'b2b_retail', 'is_active' => true,
    ]);
    $product = Product::create([
        'sku' => 'SKU-DUP', 'name' => 'Widget', 'unit' => 'pcs', 'is_active' => true, 'is_stockable' => true,
    ]);
    WarehouseProduct::create([
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'qty_on_hand'  => 100, 'qty_reserved' => 0, 'qty_in_transit' => 0, 'wac_amount' => 10,
    ]);

    return compact('warehouse', 'customer', 'product');
}

it('creates exactly one sale order on a normal submission', function (): void {
    ['warehouse' => $warehouse, 'customer' => $customer, 'product' => $product] = saleOrderCreateFixture();

    Livewire::test(SaleOrderFormPage::class)
        ->set('warehouse_id', $warehouse->id)
        ->set('customer_id', $customer->id)
        ->set('items.0.product_id', $product->id)
        ->set('items.0.qty_ordered', 2)
        ->set('items.0.unit_price_local', 10)
        ->call('save')
        ->assertHasNoErrors();

    expect(SaleOrder::count())->toBe(1);
});

it('does not create a duplicate sale order when a second submission races an in-flight one', function (): void {
    ['warehouse' => $warehouse, 'customer' => $customer, 'product' => $product] = saleOrderCreateFixture();

    $component = Livewire::test(SaleOrderFormPage::class)
        ->set('warehouse_id', $warehouse->id)
        ->set('customer_id', $customer->id)
        ->set('items.0.product_id', $product->id)
        ->set('items.0.qty_ordered', 2)
        ->set('items.0.unit_price_local', 10);

    $token = $component->get('form_token');

    // Simulate another request for this exact form (same double-click / retry) already
    // holding the lock while its own createSaleOrder() call is still in progress.
    $lock = Cache::lock("inventory.sale-order.create.{$token}", 30);
    $lock->get();

    try {
        $component->call('save');
    } finally {
        $lock->release();
    }

    expect(SaleOrder::count())->toBe(0);

    // Once the in-flight request's lock is released, the same form can still submit normally.
    $component->call('save')->assertHasNoErrors();

    expect(SaleOrder::count())->toBe(1);
});

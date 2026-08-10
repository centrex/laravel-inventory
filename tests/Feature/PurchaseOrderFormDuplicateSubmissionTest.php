<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Transactions\PurchaseOrderFormPage;
use Centrex\Inventory\Models\{Product, PurchaseOrder, Supplier, Warehouse};
use Illuminate\Support\Facades\{Cache, Gate};
use Livewire\Livewire;

function purchaseOrderCreateFixture(): array
{
    Gate::define('inventory.purchase-orders.create', fn ($user = null) => true);

    $warehouse = Warehouse::create([
        'code' => 'W-PO-DUP', 'name' => 'PO Dup Warehouse', 'country_code' => 'GB', 'currency' => 'BDT', 'is_active' => true,
    ]);
    $supplier = Supplier::create([
        'code' => 'SUP-DUP', 'name' => 'Dup Supplier', 'currency' => 'BDT',
    ]);
    $product = Product::create([
        'sku' => 'SKU-PO-DUP', 'name' => 'Widget', 'unit' => 'pcs', 'is_active' => true, 'is_stockable' => true,
    ]);

    return compact('warehouse', 'supplier', 'product');
}

it('creates exactly one purchase order on a normal submission', function (): void {
    ['warehouse' => $warehouse, 'supplier' => $supplier, 'product' => $product] = purchaseOrderCreateFixture();

    Livewire::test(PurchaseOrderFormPage::class)
        ->set('warehouse_id', $warehouse->id)
        ->set('supplier_id', $supplier->id)
        ->set('items.0.product_id', $product->id)
        ->set('items.0.qty_ordered', 5)
        ->set('items.0.unit_price_local', 20)
        ->call('save')
        ->assertHasNoErrors();

    expect(PurchaseOrder::count())->toBe(1);
});

it('does not create a duplicate purchase order when a second submission races an in-flight one', function (): void {
    ['warehouse' => $warehouse, 'supplier' => $supplier, 'product' => $product] = purchaseOrderCreateFixture();

    $component = Livewire::test(PurchaseOrderFormPage::class)
        ->set('warehouse_id', $warehouse->id)
        ->set('supplier_id', $supplier->id)
        ->set('items.0.product_id', $product->id)
        ->set('items.0.qty_ordered', 5)
        ->set('items.0.unit_price_local', 20);

    $token = $component->get('form_token');

    $lock = Cache::lock("inventory.purchase-order.create.{$token}", 30);
    $lock->get();

    try {
        $component->call('save');
    } finally {
        $lock->release();
    }

    expect(PurchaseOrder::count())->toBe(0);

    $component->call('save')->assertHasNoErrors();

    expect(PurchaseOrder::count())->toBe(1);
});

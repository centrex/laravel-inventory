<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Transactions\PurchaseOrderFormPage;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Product, Supplier, Warehouse};
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

it('multiplies local-currency amounts by the exchange rate when editing a purchase order', function (): void {
    Gate::define('inventory.purchase-orders.edit', fn ($user = null) => true);

    $warehouse = Warehouse::create([
        'code' => 'W-PO-FX', 'name' => 'PO FX Warehouse', 'country_code' => 'GB', 'currency' => 'BDT', 'is_active' => true,
    ]);
    $supplier = Supplier::create([
        'code' => 'SUP-FX', 'name' => 'FX Supplier', 'currency' => 'BDT',
    ]);
    $product = Product::create([
        'sku' => 'SKU-PO-FX', 'name' => 'Widget', 'unit' => 'pcs', 'is_active' => true, 'is_stockable' => true,
    ]);

    $purchaseOrder = app(Inventory::class)->createPurchaseOrder([
        'warehouse_id'  => $warehouse->id,
        'supplier_id'   => $supplier->id,
        'currency'      => 'BDT',
        'exchange_rate' => 1,
        'items'         => [
            ['product_id' => $product->id, 'qty_ordered' => 2, 'unit_price_local' => 10],
        ],
    ]);

    Livewire::test(PurchaseOrderFormPage::class, ['recordId' => $purchaseOrder->id])
        ->set('currency', 'USD')
        ->set('exchange_rate', 120)
        ->set('items.0.unit_price_local', 10)
        ->set('items.0.qty_ordered', 2)
        ->set('tax_local', 5)
        ->set('shipping_local', 3)
        ->call('save')
        ->assertHasNoErrors();

    $purchaseOrder->refresh();
    $item = $purchaseOrder->items()->first();

    expect((float) $purchaseOrder->exchange_rate)->toBe(120.0)
        ->and((float) $item->unit_price_local)->toBe(10.0)
        ->and((float) $item->unit_price_amount)->toBe(1200.0)
        ->and((float) $item->line_total_local)->toBe(20.0)
        ->and((float) $item->line_total_amount)->toBe(2400.0)
        ->and((float) $purchaseOrder->subtotal_local)->toBe(20.0)
        ->and((float) $purchaseOrder->subtotal_amount)->toBe(2400.0)
        ->and((float) $purchaseOrder->tax_local)->toBe(5.0)
        ->and((float) $purchaseOrder->tax_amount)->toBe(600.0)
        ->and((float) $purchaseOrder->shipping_local)->toBe(3.0)
        ->and((float) $purchaseOrder->shipping_amount)->toBe(360.0)
        ->and((float) $purchaseOrder->total_local)->toBe(28.0)
        ->and((float) $purchaseOrder->total_amount)->toBe(3360.0);
});

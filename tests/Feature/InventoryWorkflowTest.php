<?php

declare(strict_types = 1);

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{PriceTier, Product, PurchaseOrderItem, Supplier, Warehouse, WarehouseProduct};

beforeEach(function (): void {
    app(Inventory::class)->seedPriceTiers();
});

it('prevents crossing purchase order items when creating stock receipts', function (): void {
    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create([
        'code'         => 'W1',
        'name'         => 'Main Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $supplier = Supplier::create([
        'code'     => 'SUP-1',
        'name'     => 'Supplier',
        'currency' => 'BDT',
    ]);
    $product = Product::create([
        'sku'          => 'SKU-1',
        'name'         => 'Widget',
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);

    $poOne = $inventory->createPurchaseOrder([
        'warehouse_id' => $warehouse->id,
        'supplier_id'  => $supplier->id,
        'currency'     => 'BDT',
        'items'        => [[
            'product_id'       => $product->id,
            'qty_ordered'      => 5,
            'unit_price_local' => 100,
        ]],
    ]);

    $poTwo = $inventory->createPurchaseOrder([
        'warehouse_id' => $warehouse->id,
        'supplier_id'  => $supplier->id,
        'currency'     => 'BDT',
        'items'        => [[
            'product_id'       => $product->id,
            'qty_ordered'      => 7,
            'unit_price_local' => 120,
        ]],
    ]);

    $wrongItem = PurchaseOrderItem::where('purchase_order_id', $poTwo->id)->firstOrFail();

    $inventory->createStockReceipt($poOne->id, [[
        'purchase_order_item_id' => $wrongItem->id,
        'qty_received'           => 1,
    ]]);
})->throws(InvalidArgumentException::class);

it('prevents fulfilling more than the remaining sale order quantity', function (): void {
    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create([
        'code'         => 'W2',
        'name'         => 'Sales Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $product = Product::create([
        'sku'          => 'SKU-2',
        'name'         => 'Phone',
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);

    WarehouseProduct::create([
        'warehouse_id' => $warehouse->id,
        'product_id'   => $product->id,
        'qty_on_hand'  => 10,
        'qty_reserved' => 0,
        'qty_in_transit' => 0,
        'wac_amount'      => 1000,
    ]);

    $saleOrder = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'currency'        => 'BDT',
        'price_tier_code' => PriceTier::where('code', 'retail')->value('code'),
        'items'           => [[
            'product_id'  => $product->id,
            'qty_ordered' => 5,
            'unit_price_local' => 1500,
        ]],
    ]);

    $inventory->confirmSaleOrder($saleOrder->id);
    $inventory->reserveStock($saleOrder->id);

    $itemId = $saleOrder->fresh('items')->items->first()->id;

    $inventory->fulfillSaleOrder($saleOrder->id, [$itemId => 6]);
})->throws(InvalidArgumentException::class);

it('prevents over receiving transferred stock on repeated calls', function (): void {
    $inventory = app(Inventory::class);
    $source = Warehouse::create([
        'code'         => 'W3',
        'name'         => 'Source',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $destination = Warehouse::create([
        'code'         => 'W4',
        'name'         => 'Destination',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $product = Product::create([
        'sku'          => 'SKU-3',
        'name'         => 'Cable',
        'unit'         => 'pcs',
        'is_stockable' => true,
        'weight_kg'    => 1,
    ]);

    WarehouseProduct::create([
        'warehouse_id'   => $source->id,
        'product_id'     => $product->id,
        'qty_on_hand'    => 20,
        'qty_reserved'   => 0,
        'qty_in_transit' => 0,
        'wac_amount'        => 100,
    ]);

    $transfer = $inventory->createTransfer([
        'from_warehouse_id' => $source->id,
        'to_warehouse_id'   => $destination->id,
        'items'             => [[
            'product_id' => $product->id,
            'qty_sent'   => 10,
        ]],
    ]);

    $inventory->dispatchTransfer($transfer->id);

    $itemId = $transfer->fresh('items')->items->first()->id;

    $inventory->receiveTransfer($transfer->id, [$itemId => 4]);
    $inventory->receiveTransfer($transfer->id, [$itemId => 7]);
})->throws(InvalidArgumentException::class);

it('exposes inventory api routes', function (): void {
    $response = $this->postJson('/api/inventory/exchange-rates/set', [
        'currency' => 'USD',
        'rate' => 110,
        'date'     => '2026-04-11',
    ]);

    $response->assertOk()
        ->assertJsonPath('currency', 'USD');
});

it('syncs purchase receipts into accounting when the erp bridge is available', function (): void {
    if (!class_exists('Centrex\\Accounting\\Models\\Account')) {
        $this->markTestSkipped('Accounting package is not available in this test environment.');
    }

    $accountClass = 'Centrex\\Accounting\\Models\\Account';
    $billClass = 'Centrex\\Accounting\\Models\\Bill';
    $entryClass = 'Centrex\\Accounting\\Models\\JournalEntry';

    $accountClass::create(['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'is_active' => true]);
    $accountClass::create(['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability', 'is_active' => true]);
    $accountClass::create(['code' => '4900', 'name' => 'Inventory Gain', 'type' => 'income', 'is_active' => true]);
    $accountClass::create(['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'is_active' => true]);

    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create([
        'code'         => 'W5',
        'name'         => 'Receiving Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $supplier = Supplier::create([
        'code'     => 'SUP-ERP-1',
        'name'     => 'ERP Supplier',
        'currency' => 'BDT',
    ]);
    $product = Product::create([
        'sku'          => 'SKU-ERP-1',
        'name'         => 'Router',
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);

    $purchaseOrder = $inventory->createPurchaseOrder([
        'warehouse_id' => $warehouse->id,
        'supplier_id'  => $supplier->id,
        'currency'     => 'BDT',
        'items'        => [[
            'product_id'       => $product->id,
            'qty_ordered'      => 3,
            'unit_price_local' => 200,
        ]],
    ]);

    expect($purchaseOrder->fresh()->accounting_bill_id)->not->toBeNull();
    expect($billClass::where('inventory_purchase_order_id', $purchaseOrder->id)->exists())->toBeTrue();

    $receipt = $inventory->createStockReceipt($purchaseOrder->id, [[
        'purchase_order_item_id' => $purchaseOrder->fresh('items')->items->first()->id,
        'qty_received'           => 3,
    ]]);

    $postedReceipt = $inventory->postStockReceipt($receipt->id);

    expect($postedReceipt->accounting_journal_entry_id)->not->toBeNull();
    expect(
        $entryClass::where('source_type', \Centrex\Inventory\Models\StockReceipt::class)
            ->where('source_id', $postedReceipt->id)
            ->where('source_action', 'stock_receipt')
            ->exists()
    )->toBeTrue();
});

it('syncs sales into accounting and posts cogs journals when the erp bridge is available', function (): void {
    if (!class_exists('Centrex\\Accounting\\Models\\Account')) {
        $this->markTestSkipped('Accounting package is not available in this test environment.');
    }

    $accountClass = 'Centrex\\Accounting\\Models\\Account';
    $invoiceClass = 'Centrex\\Accounting\\Models\\Invoice';
    $entryClass = 'Centrex\\Accounting\\Models\\JournalEntry';

    $accountClass::create(['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'is_active' => true]);
    $accountClass::create(['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability', 'is_active' => true]);
    $accountClass::create(['code' => '4900', 'name' => 'Inventory Gain', 'type' => 'income', 'is_active' => true]);
    $accountClass::create(['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'is_active' => true]);

    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create([
        'code'         => 'W6',
        'name'         => 'ERP Sales Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $customer = \Centrex\Inventory\Models\Customer::create([
        'code'         => 'CUS-ERP-1',
        'name'         => 'ERP Customer',
        'currency'     => 'BDT',
        'price_tier_id'=> PriceTier::where('code', 'retail')->value('id'),
        'is_active'    => true,
    ]);
    $product = Product::create([
        'sku'          => 'SKU-ERP-2',
        'name'         => 'Switch',
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);

    WarehouseProduct::create([
        'warehouse_id'   => $warehouse->id,
        'product_id'     => $product->id,
        'qty_on_hand'    => 8,
        'qty_reserved'   => 0,
        'qty_in_transit' => 0,
        'wac_amount'     => 120,
    ]);

    $saleOrder = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customer->id,
        'currency'        => 'BDT',
        'price_tier_code' => PriceTier::where('code', 'retail')->value('code'),
        'items'           => [[
            'product_id'       => $product->id,
            'qty_ordered'      => 2,
            'unit_price_local' => 200,
        ]],
    ]);

    expect($saleOrder->fresh()->accounting_invoice_id)->not->toBeNull();
    expect($invoiceClass::where('inventory_sale_order_id', $saleOrder->id)->exists())->toBeTrue();

    $inventory->confirmSaleOrder($saleOrder->id);
    $inventory->reserveStock($saleOrder->id);
    $inventory->fulfillSaleOrder($saleOrder->id);

    expect(
        $entryClass::where('source_type', \Centrex\Inventory\Models\SaleOrder::class)
            ->where('source_id', $saleOrder->id)
            ->where('source_action', 'sale_fulfillment')
            ->exists()
    )->toBeTrue();
});

it('creates ecommerce sale orders from cart instances', function (): void {
    if (!class_exists('Centrex\\Cart\\Cart')) {
        $this->markTestSkipped('Cart package is not available in this test environment.');
    }

    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create([
        'code'         => 'W7',
        'name'         => 'Ecom Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $product = Product::create([
        'sku'          => 'SKU-ERP-3',
        'name'         => 'Headset',
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);

    app(\Centrex\Cart\Cart::class)->instance('ecommerce')->add($product->id, $product->name, 2, 350);

    $response = $this->postJson('/api/inventory/channels/ecommerce/checkout', [
        'warehouse_id'   => $warehouse->id,
        'currency'       => 'BDT',
        'cart_instance'  => 'ecommerce',
        'confirm'        => true,
        'reserve'        => false,
        'fulfill'        => false,
    ]);

    $response->assertCreated()
        ->assertJsonPath('warehouse_id', $warehouse->id)
        ->assertJsonPath('status', 'confirmed');
});

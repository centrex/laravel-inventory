<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Transactions\DispatchTerminalPage;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Coupon, Customer, Product, PurchaseOrderItem, SaleOrder, SaleOrderItem, Supplier, TransferBoxItem, Warehouse, WarehouseProduct};
use Livewire\Livewire;

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

it('updates dispatch terminal orders through livewire', function (): void {
    $warehouse = Warehouse::create([
        'code'         => 'W-DISPATCH-1',
        'name'         => 'Dispatch Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $customer = Customer::create([
        'code'              => 'CUS-DISPATCH-1',
        'name'              => 'Dispatch Customer',
        'organization_name' => 'Dispatch Org',
        'currency'          => 'BDT',
        'price_tier_code'   => 'b2c_retail',
        'is_active'         => true,
    ]);
    $product = Product::create([
        'sku'          => 'SKU-DISPATCH-1',
        'name'         => 'Dispatch Product',
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);
    $saleOrder = SaleOrder::create([
        'so_number'       => 'SO-DISPATCH-1',
        'document_type'   => 'order',
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customer->id,
        'price_tier_code' => 'b2c_retail',
        'currency'        => 'BDT',
        'exchange_rate'   => 1,
        'total_local'     => 100,
        'total_amount'    => 100,
        'status'          => 'confirmed',
        'ordered_at'      => now(),
    ]);

    SaleOrderItem::create([
        'sale_order_id'     => $saleOrder->id,
        'product_id'        => $product->id,
        'price_tier_code'   => 'b2c_retail',
        'qty_ordered'       => 1,
        'unit_price_local'  => 100,
        'unit_price_amount' => 100,
        'line_total_local'  => 100,
        'line_total_amount' => 100,
    ]);

    Livewire::test(DispatchTerminalPage::class)
        ->assertSee('SO-DISPATCH-1')
        ->set("orderForms.{$saleOrder->id}.tracking_number", 'TRK-100')
        ->set("orderForms.{$saleOrder->id}.carrier", 'Courier One')
        ->set("orderForms.{$saleOrder->id}.parcel_status", 'Dispatched')
        ->set("orderForms.{$saleOrder->id}.order_status", 'shipped')
        ->set("orderForms.{$saleOrder->id}.location", 'Hub A')
        ->call('updateOrder', $saleOrder->id)
        ->assertHasNoErrors()
        ->assertSee('SO-DISPATCH-1 dispatch updated.');

    expect($saleOrder->fresh()->status->value)->toBe('shipped');
});

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
        'warehouse_id'   => $warehouse->id,
        'product_id'     => $product->id,
        'qty_on_hand'    => 10,
        'qty_reserved'   => 0,
        'qty_in_transit' => 0,
        'wac_amount'     => 1000,
    ]);

    $saleOrder = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [[
            'product_id'       => $product->id,
            'qty_ordered'      => 5,
            'unit_price_local' => 1500,
        ]],
    ]);

    $inventory->confirmSaleOrder($saleOrder->id);
    $inventory->reserveStock($saleOrder->id);

    $itemId = $saleOrder->fresh('items')->items->first()->id;

    $inventory->fulfillSaleOrder($saleOrder->id, [$itemId => 6]);
})->throws(InvalidArgumentException::class);

it('blocks sale orders that exceed a customer credit limit without approval', function (): void {
    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create([
        'code'         => 'W-CREDIT-1',
        'name'         => 'Credit Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $customer = Customer::create([
        'code'                => 'CUS-CREDIT-1',
        'name'                => 'Limited Customer',
        'currency'            => 'BDT',
        'credit_limit_amount' => 1000,
        'price_tier_code'     => 'b2c_retail',
        'is_active'           => true,
    ]);
    $product = Product::create([
        'sku'          => 'SKU-CREDIT-1',
        'name'         => 'Generator',
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);

    $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customer->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [[
            'product_id'       => $product->id,
            'qty_ordered'      => 2,
            'unit_price_local' => 600,
        ]],
    ]);
})->throws(InvalidArgumentException::class);

it('stores higher-authority credit override details on sale orders', function (): void {
    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create([
        'code'         => 'W-CREDIT-2',
        'name'         => 'Approved Credit Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $customer = Customer::create([
        'code'                => 'CUS-CREDIT-2',
        'name'                => 'Approved Customer',
        'currency'            => 'BDT',
        'credit_limit_amount' => 1000,
        'price_tier_code'     => 'b2c_retail',
        'is_active'           => true,
    ]);
    $product = Product::create([
        'sku'          => 'SKU-CREDIT-2',
        'name'         => 'Industrial Fan',
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);

    $saleOrder = $inventory->createSaleOrder([
        'warehouse_id'          => $warehouse->id,
        'customer_id'           => $customer->id,
        'currency'              => 'BDT',
        'price_tier_code'       => 'b2c_retail',
        'created_by'            => 88,
        'credit_override'       => true,
        'credit_override_notes' => 'Approved by finance manager.',
        'items'                 => [[
            'product_id'       => $product->id,
            'qty_ordered'      => 2,
            'unit_price_local' => 650,
        ]],
    ]);

    expect((float) $saleOrder->credit_limit_amount)->toBe(1000.0);
    expect((float) $saleOrder->credit_exposure_after_amount)->toBe(1300.0);
    expect($saleOrder->credit_override_required)->toBeTrue();
    expect($saleOrder->credit_override_approved_by)->toBe(88);
    expect($saleOrder->credit_override_notes)->toBe('Approved by finance manager.');
    expect($saleOrder->credit_override_approved_at)->not->toBeNull();
});

it('applies percent coupons to sale orders and stores a coupon snapshot', function (): void {
    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create([
        'code'         => 'W-COUPON-1',
        'name'         => 'Coupon Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $product = Product::create([
        'sku'          => 'SKU-COUPON-1',
        'name'         => 'Air Cooler',
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);
    $coupon = Coupon::create([
        'code'           => 'SAVE10',
        'name'           => 'Save Ten',
        'discount_type'  => 'percent',
        'discount_value' => 10,
        'is_active'      => true,
    ]);

    $saleOrder = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'coupon_code'     => 'save10',
        'shipping_local'  => 50,
        'items'           => [[
            'product_id'       => $product->id,
            'qty_ordered'      => 2,
            'unit_price_local' => 500,
        ]],
    ]);

    expect($saleOrder->coupon_id)->toBe($coupon->id);
    expect($saleOrder->coupon_code)->toBe('SAVE10');
    expect($saleOrder->coupon_discount_type)->toBe('percent');
    expect((float) $saleOrder->coupon_discount_value)->toBe(10.0);
    expect((float) $saleOrder->coupon_discount_local)->toBe(100.0);
    expect((float) $saleOrder->coupon_discount_amount)->toBe(100.0);
    expect((float) $saleOrder->shipping_local)->toBe(50.0);
    expect((float) $saleOrder->shipping_amount)->toBe(50.0);
    expect((float) $saleOrder->total_local)->toBe(950.0);
});

it('rejects expired coupons on sale orders', function (): void {
    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create([
        'code'         => 'W-COUPON-2',
        'name'         => 'Expired Coupon Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $product = Product::create([
        'sku'          => 'SKU-COUPON-2',
        'name'         => 'Monitor',
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);

    Coupon::create([
        'code'           => 'OLD25',
        'discount_type'  => 'percent',
        'discount_value' => 25,
        'is_active'      => true,
        'ends_at'        => now()->subDay(),
    ]);

    $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'coupon_code'     => 'OLD25',
        'items'           => [[
            'product_id'       => $product->id,
            'qty_ordered'      => 1,
            'unit_price_local' => 800,
        ]],
    ]);
})->throws(Illuminate\Validation\ValidationException::class);

it('enforces coupon usage limits across sale orders', function (): void {
    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create([
        'code'         => 'W-COUPON-3',
        'name'         => 'Usage Limit Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $product = Product::create([
        'sku'          => 'SKU-COUPON-3',
        'name'         => 'Printer',
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);

    Coupon::create([
        'code'           => 'ONCEONLY',
        'discount_type'  => 'fixed',
        'discount_value' => 100,
        'usage_limit'    => 1,
        'is_active'      => true,
    ]);

    $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'coupon_code'     => 'ONCEONLY',
        'items'           => [[
            'product_id'       => $product->id,
            'qty_ordered'      => 1,
            'unit_price_local' => 900,
        ]],
    ]);

    $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'coupon_code'     => 'ONCEONLY',
        'items'           => [[
            'product_id'       => $product->id,
            'qty_ordered'      => 1,
            'unit_price_local' => 900,
        ]],
    ]);
})->throws(Illuminate\Validation\ValidationException::class);

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
        'wac_amount'     => 100,
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

it('allocates transfer weight and landed cost from mixed-product boxes', function (): void {
    $inventory = app(Inventory::class);
    $source = Warehouse::create([
        'code'         => 'W-BOX-1',
        'name'         => 'Packed Source',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $destination = Warehouse::create([
        'code'         => 'W-BOX-2',
        'name'         => 'Packed Destination',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $lightProduct = Product::create([
        'sku'          => 'SKU-BOX-1',
        'name'         => 'Light Item',
        'unit'         => 'pcs',
        'is_stockable' => true,
        'weight_kg'    => 1,
    ]);
    $heavyProduct = Product::create([
        'sku'          => 'SKU-BOX-2',
        'name'         => 'Heavy Item',
        'unit'         => 'pcs',
        'is_stockable' => true,
        'weight_kg'    => 3,
    ]);

    WarehouseProduct::create([
        'warehouse_id'   => $source->id,
        'product_id'     => $lightProduct->id,
        'qty_on_hand'    => 20,
        'qty_reserved'   => 0,
        'qty_in_transit' => 0,
        'wac_amount'     => 100,
    ]);
    WarehouseProduct::create([
        'warehouse_id'   => $source->id,
        'product_id'     => $heavyProduct->id,
        'qty_on_hand'    => 20,
        'qty_reserved'   => 0,
        'qty_in_transit' => 0,
        'wac_amount'     => 60,
    ]);

    $transfer = $inventory->createTransfer([
        'from_warehouse_id'    => $source->id,
        'to_warehouse_id'      => $destination->id,
        'shipping_rate_per_kg' => 5,
        'boxes'                => [[
            'box_code'           => 'BOX-ALPHA',
            'measured_weight_kg' => 10,
            'items'              => [
                ['product_id' => $lightProduct->id, 'qty_sent' => 2],
                ['product_id' => $heavyProduct->id, 'qty_sent' => 1],
            ],
        ]],
    ]);

    $transfer = $transfer->fresh(['items', 'boxes.items']);
    $lightTransferItem = $transfer->items->firstWhere('product_id', $lightProduct->id);
    $heavyTransferItem = $transfer->items->firstWhere('product_id', $heavyProduct->id);
    $lightBoxItem = TransferBoxItem::query()->where('product_id', $lightProduct->id)->firstOrFail();
    $heavyBoxItem = TransferBoxItem::query()->where('product_id', $heavyProduct->id)->firstOrFail();

    expect((float) $transfer->total_weight_kg)->toBe(10.0);
    expect((float) $transfer->shipping_cost_amount)->toBe(50.0);

    expect((float) $lightBoxItem->allocated_weight_kg)->toBe(4.0);
    expect((float) $heavyBoxItem->allocated_weight_kg)->toBe(6.0);

    expect((float) $lightTransferItem->shipping_allocated_amount)->toBe(20.0);
    expect((float) $heavyTransferItem->shipping_allocated_amount)->toBe(30.0);
    expect((float) $lightTransferItem->unit_landed_cost_amount)->toBe(110.0);
    expect((float) $heavyTransferItem->unit_landed_cost_amount)->toBe(90.0);
});

it('can progress a purchase order from draft to received with the remaining quantity', function (): void {
    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create([
        'code'         => 'W-PO-FLOW',
        'name'         => 'Purchase Flow Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $supplier = Supplier::create([
        'code'     => 'SUP-PO-FLOW',
        'name'     => 'Purchase Flow Supplier',
        'currency' => 'BDT',
    ]);
    $product = Product::create([
        'sku'          => 'SKU-PO-FLOW',
        'name'         => 'Purchase Flow Product',
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);

    $purchaseOrder = $inventory->createPurchaseOrder([
        'warehouse_id' => $warehouse->id,
        'supplier_id'  => $supplier->id,
        'currency'     => 'BDT',
        'items'        => [[
            'product_id'       => $product->id,
            'qty_ordered'      => 4,
            'unit_price_local' => 125,
        ]],
    ]);

    expect($purchaseOrder->status->value)->toBe('draft');

    $purchaseOrder = $inventory->submitPurchaseOrder($purchaseOrder->id);
    expect($purchaseOrder->status->value)->toBe('submitted');

    $purchaseOrder = $inventory->confirmPurchaseOrder($purchaseOrder->id);
    expect($purchaseOrder->status->value)->toBe('confirmed');

    $purchaseOrder = $inventory->receivePurchaseOrder($purchaseOrder->id);
    $purchaseOrderItem = $purchaseOrder->items->first();

    expect($purchaseOrder->status->value)->toBe('received');
    expect((float) $purchaseOrderItem->qty_received)->toBe(4.0);
    expect((float) WarehouseProduct::query()
        ->where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->value('qty_on_hand'))->toBe(4.0);
});

it('can cancel a purchase order before it is received', function (): void {
    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create([
        'code'         => 'W-PO-CANCEL',
        'name'         => 'Purchase Cancel Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $supplier = Supplier::create([
        'code'     => 'SUP-PO-CANCEL',
        'name'     => 'Purchase Cancel Supplier',
        'currency' => 'BDT',
    ]);
    $product = Product::create([
        'sku'          => 'SKU-PO-CANCEL',
        'name'         => 'Purchase Cancel Product',
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);

    $purchaseOrder = $inventory->createPurchaseOrder([
        'warehouse_id' => $warehouse->id,
        'supplier_id'  => $supplier->id,
        'currency'     => 'BDT',
        'items'        => [[
            'product_id'       => $product->id,
            'qty_ordered'      => 2,
            'unit_price_local' => 75,
        ]],
    ]);

    $purchaseOrder = $inventory->cancelPurchaseOrder($purchaseOrder->id);

    expect($purchaseOrder->status->value)->toBe('cancelled');
});

it('can confirm and cancel a quotation without reserving stock', function (): void {
    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create([
        'code'         => 'W-QT-FLOW',
        'name'         => 'Quotation Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $product = Product::create([
        'sku'          => 'SKU-QT-FLOW',
        'name'         => 'Quoted Product',
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);

    WarehouseProduct::create([
        'warehouse_id'   => $warehouse->id,
        'product_id'     => $product->id,
        'qty_on_hand'    => 10,
        'qty_reserved'   => 0,
        'qty_in_transit' => 0,
        'wac_amount'     => 50,
    ]);

    $quotation = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'currency'        => 'BDT',
        'document_type'   => 'quotation',
        'price_tier_code' => 'b2c_retail',
        'items'           => [[
            'product_id'       => $product->id,
            'qty_ordered'      => 3,
            'unit_price_local' => 120,
        ]],
    ]);

    expect($quotation->document_type)->toBe('quotation');
    expect($quotation->status->value)->toBe('draft');

    $quotation = $inventory->confirmSaleOrder($quotation->id);
    expect($quotation->status->value)->toBe('confirmed');

    $quotation = $inventory->cancelSaleOrder($quotation->id);
    expect($quotation->status->value)->toBe('cancelled');
    expect((float) WarehouseProduct::query()
        ->where('warehouse_id', $warehouse->id)
        ->where('product_id', $product->id)
        ->value('qty_reserved'))->toBe(0.0);
});

it('can create a regular sale order from a quotation', function (): void {
    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create([
        'code'         => 'W-QT-CONVERT',
        'name'         => 'Quotation Conversion Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $customer = Customer::create([
        'code'                => 'C-QT-CONVERT',
        'name'                => 'Quotation Customer',
        'currency'            => 'BDT',
        'credit_limit_amount' => 999999,
        'is_active'           => true,
    ]);
    $product = Product::create([
        'sku'          => 'SKU-QT-CONVERT',
        'name'         => 'Convertible Product',
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);

    $quotation = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customer->id,
        'currency'        => 'BDT',
        'document_type'   => 'quotation',
        'price_tier_code' => 'b2b_retail',
        'items'           => [[
            'product_id'       => $product->id,
            'qty_ordered'      => 2,
            'unit_price_local' => 120,
        ]],
    ]);

    $quotation = $inventory->confirmSaleOrder($quotation->id);
    $saleOrder = $inventory->createSaleOrderFromQuotation($quotation->id);

    expect($saleOrder->document_type)->toBe('order');
    expect($saleOrder->customer_id)->toBe($customer->id);
    expect($saleOrder->items)->toHaveCount(1);
    expect((float) $saleOrder->items->first()->qty_ordered)->toBe(2.0);
    expect((float) $saleOrder->items->first()->unit_price_local)->toBe(120.0);
});

it('can create a purchase order from a requisition', function (): void {
    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create([
        'code'         => 'W-REQ-CONVERT',
        'name'         => 'Requisition Conversion Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $supplier = Supplier::create([
        'code'      => 'SUP-REQ-CONVERT',
        'name'      => 'Conversion Supplier',
        'currency'  => 'BDT',
        'is_active' => true,
    ]);
    $product = Product::create([
        'sku'          => 'SKU-REQ-CONVERT',
        'name'         => 'Procurement Product',
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);

    $requisition = $inventory->createPurchaseOrder([
        'warehouse_id'  => $warehouse->id,
        'supplier_id'   => $supplier->id,
        'currency'      => 'BDT',
        'document_type' => 'requisition',
        'items'         => [[
            'product_id'       => $product->id,
            'qty_ordered'      => 4,
            'unit_price_local' => 80,
        ]],
    ]);

    $requisition = $inventory->submitPurchaseOrder($requisition->id);
    $requisition = $inventory->confirmPurchaseOrder($requisition->id);
    $purchaseOrder = $inventory->createPurchaseOrderFromRequisition($requisition->id);

    expect($purchaseOrder->document_type)->toBe('order');
    expect($purchaseOrder->supplier_id)->toBe($supplier->id);
    expect($purchaseOrder->items)->toHaveCount(1);
    expect((float) $purchaseOrder->items->first()->qty_ordered)->toBe(4.0);
    expect((float) $purchaseOrder->items->first()->unit_price_local)->toBe(80.0);
});

it('exposes inventory api routes', function (): void {
    $response = $this->postJson('/api/inventory/exchange-rates/set', [
        'currency' => 'USD',
        'rate'     => 110,
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
        $entryClass::where('source_type', Centrex\Inventory\Models\StockReceipt::class)
            ->where('source_id', $postedReceipt->id)
            ->where('source_action', 'stock_receipt')
            ->exists(),
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
    $customer = Customer::create([
        'code'            => 'CUS-ERP-1',
        'name'            => 'ERP Customer',
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'is_active'       => true,
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
        'price_tier_code' => 'b2c_retail',
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
        $entryClass::where('source_type', Centrex\Inventory\Models\SaleOrder::class)
            ->where('source_id', $saleOrder->id)
            ->where('source_action', 'sale_fulfillment')
            ->exists(),
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

    app(Centrex\Cart\Cart::class)->instance('ecommerce')->add($product->id, $product->name, 2, 350);

    $response = $this->postJson('/api/inventory/channels/ecommerce/checkout', [
        'warehouse_id'  => $warehouse->id,
        'currency'      => 'BDT',
        'cart_instance' => 'ecommerce',
        'confirm'       => true,
        'reserve'       => false,
        'fulfill'       => false,
    ]);

    $response->assertCreated()
        ->assertJsonPath('warehouse_id', $warehouse->id)
        ->assertJsonPath('status', 'confirmed');
});

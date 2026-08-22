<?php

declare(strict_types = 1);

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Jobs\{PostStockReceiptAccountingEntryJob, SyncPurchaseOrderAccountingDocumentJob, SyncSaleOrderAccountingDocumentJob, VoidStockReceiptAccountingEntryJob};
use Centrex\Inventory\Models\{Customer, Product, Supplier, Warehouse, WarehouseProduct};
use Illuminate\Support\Facades\Queue;

/**
 * Regression coverage for the create-sale-order/create-purchase-order/post-GRN 500-or-timeout
 * bug: syncSaleOrderDocument()/syncPurchaseOrderDocument()/postStockReceipt() used to run
 * inline right after the owning DB::transaction() committed, so an exception or slow call in
 * the accounting sync (e.g. a missing GL account) turned an already-persisted document into a
 * failed response — and users retrying after that produced real duplicate sale orders in
 * production (see git history / KNOWN_ISSUES for the incident this fixed).
 *
 * The functional "it syncs ... into accounting" tests elsewhere in this suite pass under both
 * the old inline code and the new queued code, because config('queue.default') is forced to
 * 'sync' in TestCase — dispatch() just runs the job immediately either way. They can't tell
 * a queued call apart from an inline one. This test asserts the dispatch itself happens,
 * which is the only thing that actually proves the sync no longer runs on the request thread.
 */
it('dispatches the accounting sync as a queued job on sale order create, not inline', function (): void {
    Queue::fake();

    $warehouse = Warehouse::create(['code' => 'W-Q-1', 'name' => 'Queue Test Warehouse', 'country_code' => 'BD', 'currency' => 'BDT']);
    $customer = Customer::create(['code' => 'CUS-Q-1', 'name' => 'Queue Test Customer', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);
    $product = Product::create(['sku' => 'SKU-Q-1', 'name' => 'Queue Test Widget', 'unit' => 'pcs', 'is_stockable' => true]);
    WarehouseProduct::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'qty_on_hand' => 10, 'wac_amount' => 50]);

    $order = app(Inventory::class)->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customer->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [['product_id' => $product->id, 'qty_ordered' => 1, 'unit_price_local' => 100]],
    ]);

    Queue::assertPushed(SyncSaleOrderAccountingDocumentJob::class, fn ($job): bool => $job->saleOrderId === $order->id);
});

it('dispatches the accounting sync as a queued job on purchase order create and confirm', function (): void {
    Queue::fake();

    $warehouse = Warehouse::create(['code' => 'W-Q-2', 'name' => 'Queue Test Warehouse 2', 'country_code' => 'BD', 'currency' => 'BDT']);
    $supplier = Supplier::create(['code' => 'SUP-Q-1', 'name' => 'Queue Test Supplier', 'currency' => 'BDT', 'is_active' => true]);
    $product = Product::create(['sku' => 'SKU-Q-2', 'name' => 'Queue Test Widget 2', 'unit' => 'pcs', 'is_stockable' => true]);

    $inventory = app(Inventory::class);
    $po = $inventory->createPurchaseOrder([
        'warehouse_id' => $warehouse->id,
        'supplier_id'  => $supplier->id,
        'currency'     => 'BDT',
        'items'        => [['product_id' => $product->id, 'qty_ordered' => 5, 'unit_price_local' => 20]],
    ]);

    Queue::assertPushed(SyncPurchaseOrderAccountingDocumentJob::class, fn ($job): bool => $job->purchaseOrderId === $po->id);

    $inventory->submitPurchaseOrder($po->id);
    $inventory->confirmPurchaseOrder($po->id);

    Queue::assertPushed(SyncPurchaseOrderAccountingDocumentJob::class, 2);
});

it('dispatches the GRN accounting entry as a queued job on post and void', function (): void {
    Queue::fake();

    $warehouse = Warehouse::create(['code' => 'W-Q-3', 'name' => 'Queue Test Warehouse 3', 'country_code' => 'BD', 'currency' => 'BDT']);
    $supplier = Supplier::create(['code' => 'SUP-Q-2', 'name' => 'Queue Test Supplier 2', 'currency' => 'BDT', 'is_active' => true]);
    $product = Product::create(['sku' => 'SKU-Q-3', 'name' => 'Queue Test Widget 3', 'unit' => 'pcs', 'is_stockable' => true]);

    $inventory = app(Inventory::class);
    $po = $inventory->createPurchaseOrder([
        'warehouse_id' => $warehouse->id,
        'supplier_id'  => $supplier->id,
        'currency'     => 'BDT',
        'items'        => [['product_id' => $product->id, 'qty_ordered' => 5, 'unit_price_local' => 20]],
    ]);
    $inventory->submitPurchaseOrder($po->id);
    $inventory->confirmPurchaseOrder($po->id);

    $poItemId = $po->fresh('items')->items->first()->id;
    $grn = $inventory->createStockReceipt($po->id, [
        ['purchase_order_item_id' => $poItemId, 'qty_received' => 5, 'unit_cost_local' => 20],
    ]);

    $inventory->postStockReceipt($grn->id);
    Queue::assertPushed(PostStockReceiptAccountingEntryJob::class, fn ($job): bool => $job->stockReceiptId === $grn->id);

    $inventory->voidStockReceipt($grn->id);
    Queue::assertPushed(VoidStockReceiptAccountingEntryJob::class, fn ($job): bool => $job->stockReceiptId === $grn->id);
});

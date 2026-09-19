<?php

declare(strict_types = 1);

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Product, Supplier, Warehouse};
use Centrex\Inventory\Support\ErpIntegration;

/**
 * Regression coverage for ErpIntegration::post*() no longer racing on a bare
 * "column already set?" check. Each test here simulates two overlapping calls (e.g. the
 * original run of a queue job plus a redelivery/retry, or two concurrent requests) by
 * loading two separate copies of the model — both still showing accounting_journal_entry_id
 * = null — before either one posts, then invoking the same ErpIntegration method on each.
 * A stale in-memory check alone (the pre-fix guard) can't catch this; only a fresh, locked
 * DB read at post time can. The ERP bridge is disabled while setting up the "posted but not
 * yet accounting-synced" state because Inventory::postAdjustment()/postStockReceipt() would
 * otherwise perform that first accounting sync themselves (synchronously, or via a queue job
 * that runs inline under the test's sync queue driver) before this test gets a chance to.
 */
function skipIfAccountingUnavailable(PHPUnit\Framework\TestCase $test): void
{
    if (!class_exists('Centrex\\Accounting\\Models\\Account')) {
        $test->markTestSkipped('Accounting package is not available in this test environment.');
    }
}

it('does not double-post a journal entry when two overlapping calls post the same GRN', function (): void {
    skipIfAccountingUnavailable($this);

    $accountClass = 'Centrex\\Accounting\\Models\\Account';
    $entryClass = 'Centrex\\Accounting\\Models\\JournalEntry';

    $accountClass::create(['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'is_active' => true]);
    $accountClass::create(['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability', 'is_active' => true]);
    $accountClass::create(['code' => '2050', 'name' => 'GRN Clearing', 'type' => 'liability', 'is_active' => true]);
    $accountClass::create(['code' => '4900', 'name' => 'Inventory Gain', 'type' => 'revenue', 'is_active' => true]);
    $accountClass::create(['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'is_active' => true]);

    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create(['code' => 'W-IDEM-1', 'name' => 'Idempotency Warehouse', 'country_code' => 'BD', 'currency' => 'BDT']);
    $supplier = Supplier::create(['code' => 'SUP-IDEM-1', 'name' => 'Idempotency Supplier', 'currency' => 'BDT']);
    $product = Product::create(['sku' => 'SKU-IDEM-1', 'name' => 'Widget', 'unit' => 'pcs', 'is_stockable' => true]);

    $purchaseOrder = $inventory->createPurchaseOrder([
        'warehouse_id' => $warehouse->id,
        'supplier_id'  => $supplier->id,
        'currency'     => 'BDT',
        'items'        => [['product_id' => $product->id, 'qty_ordered' => 3, 'unit_price_local' => 200]],
    ]);

    $receipt = $inventory->createStockReceipt($purchaseOrder->id, [[
        'purchase_order_item_id' => $purchaseOrder->fresh('items')->items->first()->id,
        'qty_received'           => 3,
    ]]);

    config(['inventory.erp.accounting.enabled' => false]);
    $postedReceipt = $inventory->postStockReceipt($receipt->id);
    config(['inventory.erp.accounting.enabled' => true]);

    $erp = app(ErpIntegration::class);
    $workerA = Centrex\Inventory\Models\StockReceipt::find($postedReceipt->id);
    $workerB = Centrex\Inventory\Models\StockReceipt::find($postedReceipt->id);

    $firstId = $erp->postStockReceipt($workerA);
    $secondId = $erp->postStockReceipt($workerB);

    expect($firstId)->not->toBeNull();
    expect($secondId)->toBe($firstId);
    expect(
        $entryClass::where('source_type', Centrex\Inventory\Models\StockReceipt::class)
            ->where('source_id', $postedReceipt->id)
            ->where('source_action', 'stock_receipt')
            ->count(),
    )->toBe(1);
});

it('does not double-post a journal entry when two overlapping calls post the same adjustment', function (): void {
    skipIfAccountingUnavailable($this);

    $accountClass = 'Centrex\\Accounting\\Models\\Account';
    $entryClass = 'Centrex\\Accounting\\Models\\JournalEntry';

    $accountClass::create(['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'is_active' => true]);
    $accountClass::create(['code' => '4900', 'name' => 'Inventory Gain', 'type' => 'revenue', 'is_active' => true]);
    $accountClass::create(['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'is_active' => true]);

    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create(['code' => 'W-IDEM-2', 'name' => 'Idempotency Warehouse 2', 'country_code' => 'BD', 'currency' => 'BDT']);
    $product = Product::create(['sku' => 'SKU-IDEM-2', 'name' => 'Gadget', 'unit' => 'pcs', 'is_stockable' => true]);

    Centrex\Inventory\Models\WarehouseProduct::create([
        'warehouse_id' => $warehouse->id,
        'product_id'   => $product->id,
        'qty_on_hand'  => 10,
        'wac_amount'   => 50,
    ]);

    $adjustment = $inventory->createAdjustment([
        'warehouse_id' => $warehouse->id,
        'reason'       => 'cycle_count',
        'items'        => [['product_id' => $product->id, 'qty_actual' => 15]],
    ]);

    config(['inventory.erp.accounting.enabled' => false]);
    $postedAdjustment = $inventory->postAdjustment($adjustment->id);
    config(['inventory.erp.accounting.enabled' => true]);

    $erp = app(ErpIntegration::class);
    $workerA = Centrex\Inventory\Models\Adjustment::find($postedAdjustment->id);
    $workerB = Centrex\Inventory\Models\Adjustment::find($postedAdjustment->id);

    $firstId = $erp->postAdjustment($workerA);
    $secondId = $erp->postAdjustment($workerB);

    expect($firstId)->not->toBeNull();
    expect($secondId)->toBe($firstId);
    expect(
        $entryClass::where('source_type', Centrex\Inventory\Models\Adjustment::class)
            ->where('source_id', $postedAdjustment->id)
            ->where('source_action', 'inventory_adjustment')
            ->count(),
    )->toBe(1);
});

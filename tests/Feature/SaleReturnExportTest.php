<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Transactions\SaleReturnTable;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Customer, Product, Warehouse, WarehouseProduct};

/**
 * Regression test for the "Refundable" column: its UI cell is a ->view()
 * showing a status badge + the credit memo's refundable_amount, but CSV
 * export never renders Blade views — it only reads a column's plain $key
 * via data_get(). Before Column::exportKey() existed, the export silently
 * showed the credit memo's raw status ('issued') instead of the refund
 * value. See tallui's DataTableExportKeyTest for the underlying mechanism.
 */
it('exports the credit memo refundable amount, not its status, in the Refundable column', function (): void {
    if (!class_exists('Centrex\\Accounting\\Models\\CreditMemo')) {
        $this->markTestSkipped('Accounting package (with credit memo support) is not available in this test environment.');
    }

    $accountClass = 'Centrex\\Accounting\\Models\\Account';

    $accountClass::create(['code' => '1000', 'name' => 'Cash', 'type' => 'asset', 'is_active' => true]);
    $accountClass::create(['code' => '1200', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
    $accountClass::create(['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'is_active' => true]);
    $accountClass::create(['code' => '2300', 'name' => 'Sales Tax Payable', 'type' => 'liability', 'is_active' => true]);
    $accountClass::create(['code' => '4000', 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
    $accountClass::create(['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'is_active' => true]);
    $accountClass::create(['code' => '6134', 'name' => 'Sales Returns & Allowances', 'type' => 'expense', 'is_active' => true]);

    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create([
        'code'         => 'W-EXP',
        'name'         => 'Export Test Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $customer = Customer::create([
        'code'            => 'CUS-EXP-1',
        'name'            => 'Export Test Customer',
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'is_active'       => true,
    ]);
    $product = Product::create([
        'sku'          => 'SKU-EXP-1',
        'name'         => 'Exportable Widget',
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);

    WarehouseProduct::create([
        'warehouse_id'   => $warehouse->id,
        'product_id'     => $product->id,
        'qty_on_hand'    => 10,
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

    $inventory->confirmSaleOrder($saleOrder->id);
    $inventory->reserveStock($saleOrder->id);
    $inventory->fulfillSaleOrder($saleOrder->id);

    // fulfillSaleOrder() already posts the invoice as part of fulfillment
    // (ErpIntegration::fulfillSaleOrder() -> Accounting::postInvoice()) — no
    // separate postInvoice() call needed/possible here.
    $saleReturn = $inventory->createSaleReturn([
        'sale_order_id' => $saleOrder->id,
        'warehouse_id'  => $warehouse->id,
        'customer_id'   => $customer->id,
        'returned_at'   => now(),
        'items'         => [[
            'product_id'        => $product->id,
            'qty_returned'      => 1,
            'unit_price_amount' => 200,
            'unit_cost_amount'  => 120,
        ]],
    ]);
    $saleReturn = $inventory->postSaleReturn($saleReturn->id);

    $memo = $saleReturn->fresh()->creditMemo;
    expect($memo)->not->toBeNull();

    $table = new SaleReturnTable;
    $table->columnDefs = array_map(fn ($col): array => $col->toArray(), $table->columns());

    ob_start();
    $table->exportCsv()->sendContent();
    $csv = (string) ob_get_clean();

    $lines = array_values(array_filter(explode("\n", $csv)));
    $header = str_getcsv(ltrim($lines[0], "\xEF\xBB\xBF"));
    $refundableIndex = array_search('Refundable', $header, true);

    expect($refundableIndex)->not->toBeFalse();

    $exportedRow = collect($lines)
        ->slice(1)
        ->map(static fn (string $line): array => str_getcsv($line))
        ->first(fn (array $row): bool => ($row[0] ?? null) === $saleReturn->return_number);

    expect($exportedRow)->not->toBeNull()
        ->and((float) $exportedRow[$refundableIndex])->toBe((float) $memo->refundable_amount)
        ->and($exportedRow[$refundableIndex])->not->toBe('issued');
});

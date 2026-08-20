<?php

declare(strict_types = 1);

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Mail\DueAmountDiscrepancyReportMail;
use Centrex\Inventory\Models\{Customer, Product, SaleOrder, Warehouse, WarehouseProduct};
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
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
});

function makeDueAmountSaleOrder(): SaleOrder
{
    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create([
        'code'         => 'W-DUE',
        'name'         => 'Due Amount Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $customer = Customer::create([
        'code'            => 'CUS-DUE-1',
        'name'            => 'Due Amount Customer',
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'is_active'       => true,
    ]);
    $product = Product::create([
        'sku'          => 'SKU-DUE-1',
        'name'         => 'Returnable Widget',
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

    $invoiceClass = 'Centrex\\Accounting\\Models\\Invoice';
    $invoice = $invoiceClass::findOrFail($saleOrder->fresh()->accounting_invoice_id);

    // fulfillSaleOrder() may already post the invoice's journal entry as part of the sync —
    // only post explicitly when that hasn't happened yet, so AR exists to credit either way.
    if ($invoice->journal_entry_id === null) {
        app('accounting')->postInvoice($invoice);
    }

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
    $inventory->postSaleReturn($saleReturn->id);

    return $saleOrder->fresh();
}

it('reports no discrepancies when SaleOrder::due_amount already matches its invoice balance', function (): void {
    makeDueAmountSaleOrder();

    $this->artisan('inventory:check-due-amounts')
        ->expectsOutputToContain('No due-amount discrepancies found.')
        ->assertExitCode(0);
});

it('detects a sale order whose due_amount has drifted from its invoice balance', function (): void {
    $saleOrder = makeDueAmountSaleOrder();

    // Simulate the InvoicePaymentObserver regression: due_amount overwritten with a stale
    // total-paid_amount style value that ignores the credit memo already issued against it.
    $saleOrder->forceFill(['due_amount' => 999.0])->saveQuietly();

    $this->artisan('inventory:check-due-amounts')
        ->expectsOutputToContain($saleOrder->so_number)
        ->assertExitCode(0);

    expect((float) $saleOrder->fresh()->due_amount)->toBe(999.0);
});

it('fixes a mismatched sale order due_amount with --fix', function (): void {
    $saleOrder = makeDueAmountSaleOrder();
    $expectedDue = (float) $saleOrder->due_amount;

    $saleOrder->forceFill(['due_amount' => 999.0])->saveQuietly();
    expect((float) $saleOrder->fresh()->due_amount)->toBe(999.0);

    $this->artisan('inventory:check-due-amounts', ['--fix' => true])
        ->assertExitCode(0);

    expect((float) $saleOrder->fresh()->due_amount)->toBe($expectedDue);
});

it('emails the report to the given address when mismatches are found', function (): void {
    Mail::fake();

    $saleOrder = makeDueAmountSaleOrder();
    $saleOrder->forceFill(['due_amount' => 999.0])->saveQuietly();

    $this->artisan('inventory:check-due-amounts', ['--email' => ['ops@example.com']])
        ->assertExitCode(0);

    Mail::assertSent(DueAmountDiscrepancyReportMail::class, function (DueAmountDiscrepancyReportMail $mail) use ($saleOrder) {
        return $mail->hasTo('ops@example.com') && str_contains($mail->body, $saleOrder->so_number);
    });
});

it('does not send an email when no --email option is given', function (): void {
    Mail::fake();

    makeDueAmountSaleOrder();

    $this->artisan('inventory:check-due-amounts')
        ->assertExitCode(0);

    Mail::assertNothingSent();
});

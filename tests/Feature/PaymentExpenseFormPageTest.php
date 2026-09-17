<?php

declare(strict_types = 1);

use Centrex\Accounting\Models\{Account, Invoice};
use Centrex\Inventory\Http\Livewire\Transactions\{ExpenseFormPage, PaymentFormPage};
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Customer, Expense, Payment, Product, Warehouse, WarehouseProduct};
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\{Gate, Route};
use Livewire\Livewire;

/**
 * The real inventory.payments.* and inventory.expenses.* routes are only registered when
 * inventory.payments_ui.enabled is true (see routes/web.php), which this test environment's
 * default config leaves off (see PaymentExpenseMirrorSyncTest's gating test). PaymentFormPage/
 * ExpenseFormPage::save() redirect to those route names on success, so stand-ins are registered
 * here purely so that redirect() call resolves — this test exercises the component's own logic
 * (validation, calling into the Accounting facade, the mirror row landing correctly), not the
 * route-gating itself.
 */
function registerPaymentExpenseTargetRoutesForTest(): void
{
    if (!Route::has('inventory.payments.index')) {
        Route::get('/test-inventory-payments', fn () => 'ok')->name('inventory.payments.index');
    }

    if (!Route::has('inventory.expenses.index')) {
        Route::get('/test-inventory-expenses', fn () => 'ok')->name('inventory.expenses.index');
    }
}

function actingAsInventoryAdminForPaymentTests(): void
{
    Gate::define('inventory-admin', fn () => true);
    test()->actingAs(new class() extends Authenticatable
    {
        protected $table = 'users';

        public $id = 1;
    });
}

it('records an invoice payment through PaymentFormPage and mirrors it', function (): void {
    if (!class_exists(Account::class)) {
        $this->markTestSkipped('Accounting package is not available in this test environment.');
    }

    Account::create(['code' => '1000', 'name' => 'Cash', 'type' => 'asset', 'is_active' => true]);
    Account::create(['code' => '1200', 'name' => 'Accounts Receivable', 'type' => 'asset', 'is_active' => true]);
    Account::create(['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'is_active' => true]);
    Account::create(['code' => '2300', 'name' => 'Sales Tax Payable', 'type' => 'liability', 'is_active' => true]);
    Account::create(['code' => '4000', 'name' => 'Sales Revenue', 'type' => 'revenue', 'is_active' => true]);
    Account::create(['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'is_active' => true]);

    registerPaymentExpenseTargetRoutesForTest();

    $warehouse = Warehouse::create(['code' => 'W-PFP-1', 'name' => 'PFP Warehouse', 'country_code' => 'BD', 'currency' => 'BDT']);
    $customer = Customer::create(['code' => 'CUS-PFP-1', 'name' => 'PFP Customer', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);
    $product = Product::create(['sku' => 'SKU-PFP-1', 'name' => 'Widget PFP', 'unit' => 'pcs', 'is_stockable' => true]);
    WarehouseProduct::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'qty_on_hand' => 10, 'wac_amount' => 50]);

    $inventory = app(Inventory::class);
    $order = $inventory->createSaleOrder([
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customer->id,
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'items'           => [['product_id' => $product->id, 'qty_ordered' => 2, 'unit_price_local' => 300]],
    ]);
    $inventory->confirmSaleOrder($order->id);
    $inventory->reserveStock($order->id);
    $inventory->fulfillSaleOrder($order->id);

    actingAsInventoryAdminForPaymentTests();

    Livewire::test(PaymentFormPage::class)
        ->set('target_type', 'sale')
        ->set('sale_order_id', $order->id)
        ->set('amount', 150)
        ->set('payment_date', today()->toDateString())
        ->set('payment_method', 'cash')
        ->set('account_code', '1000')
        ->call('save')
        ->assertRedirect(route('inventory.payments.index'));

    $invoice = Invoice::findOrFail($order->fresh()->accounting_invoice_id);
    $mirror = Payment::where('sale_order_id', $order->id)->first();

    expect($mirror)->not->toBeNull()
        ->and((float) $mirror->amount)->toBe(150.0)
        ->and($mirror->payable_id)->toBe($invoice->id);
});

it('records a standalone expense through ExpenseFormPage and mirrors it', function (): void {
    if (!class_exists(Account::class)) {
        $this->markTestSkipped('Accounting package is not available in this test environment.');
    }

    Account::create(['code' => '1000', 'name' => 'Cash', 'type' => 'asset', 'is_active' => true]);
    Account::create(['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability', 'is_active' => true]);
    $account = Account::create(['code' => '6900', 'name' => 'Office Supplies', 'type' => 'expense', 'is_active' => true]);

    registerPaymentExpenseTargetRoutesForTest();
    actingAsInventoryAdminForPaymentTests();

    Livewire::test(ExpenseFormPage::class)
        ->set('account_id', $account->id)
        ->set('expense_date', today()->toDateString())
        ->set('subtotal', 250)
        ->set('tax_amount', 0)
        ->set('payment_method', 'cash')
        ->set('vendor_name', 'Acme Supplies Co')
        ->call('save')
        ->assertRedirect(route('inventory.expenses.index'));

    $mirror = Expense::where('account_code', '6900')->first();

    expect($mirror)->not->toBeNull()
        ->and($mirror->direction)->toBe('standalone')
        ->and((float) $mirror->total)->toBe(250.0)
        ->and($mirror->vendor_name)->toBe('Acme Supplies Co');
});

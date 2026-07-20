<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Transactions\AgingReportPage;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Customer, Product, Warehouse, WarehouseProduct};
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/**
 * Regression coverage for the Aging Report page's "Stock Aging" / "Due Aging" tabs —
 * <x-tallui-tab> only renders panels via named slots (<x-slot:id>), not a default slot
 * with manual x-show divs, so this locks in the working pattern.
 */
it('renders both the stock aging and due aging tabs', function (): void {
    Gate::define('inventory.reports.view', fn ($user = null): bool => true);

    Livewire::test(AgingReportPage::class)
        ->assertSee('Aging Report')
        ->assertSee('Stock Aging')
        ->assertSee('Due Aging');
});

it('groups due aging by customer and shows their invoices in a detail modal', function (): void {
    Gate::define('inventory.reports.view', fn ($user = null): bool => true);

    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create([
        'code'         => 'W-ARDA-1',
        'name'         => 'Aging Report Due Aging Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $customer = Customer::create(['code' => 'CUS-ARDA-1', 'name' => 'Aging Report Customer', 'organization_name' => 'Aging Report Customer', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);
    $product = Product::create(['sku' => 'SKU-ARDA-1', 'name' => 'Aging Report Widget', 'unit' => 'pcs', 'is_stockable' => true]);

    WarehouseProduct::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'qty_on_hand' => 50, 'wac_amount' => 10]);

    $order = $inventory->createSaleOrder([
        'warehouse_id' => $warehouse->id, 'customer_id' => $customer->id, 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail',
        'items' => [['product_id' => $product->id, 'qty_ordered' => 1, 'unit_price_local' => 250]],
    ]);
    $inventory->confirmSaleOrder($order->id);

    Livewire::test(AgingReportPage::class)
        ->assertSee('Due Aging by Customer')
        ->assertSee('Aging Report Customer')
        ->call('viewCustomerAging', $customer->id)
        ->assertSee($order->so_number)
        ->assertSee('250.00');
});

it('computes days overdue relative to today regardless of the fromDate filter', function (): void {
    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create([
        'code'         => 'W-ARDA-2',
        'name'         => 'Aging Report From Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $customer = Customer::create(['code' => 'CUS-ARDA-2', 'name' => 'From Customer', 'organization_name' => 'From Customer', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);
    $product = Product::create(['sku' => 'SKU-ARDA-2', 'name' => 'From Widget', 'unit' => 'pcs', 'is_stockable' => true]);

    WarehouseProduct::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'qty_on_hand' => 50, 'wac_amount' => 10]);

    $order = $inventory->createSaleOrder([
        'warehouse_id' => $warehouse->id, 'customer_id' => $customer->id, 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail',
        'items' => [['product_id' => $product->id, 'qty_ordered' => 1, 'unit_price_local' => 100]],
    ]);
    $inventory->confirmSaleOrder($order->id);
    $order->update(['ordered_at' => now()->subDays(45)]);

    // With no lower bound, the order shows up, 45 days old (31-60 bucket) — relative to today.
    $unfiltered = $inventory->dueAgingReport($customer->id)->first();
    expect($unfiltered['days_overdue'])->toBe(45)
        ->and($unfiltered['age_bucket'])->toBe('31-60');

    // fromDate before the order was placed still includes it, and days_overdue is
    // still relative to *today* (45), not to fromDate — fromDate only filters inclusion.
    $fromBefore = $order->ordered_at->copy()->subDays(10)->toDateString();
    $included = $inventory->dueAgingReport($customer->id, $fromBefore)->first();
    expect($included['days_overdue'])->toBe(45);

    // fromDate after the order was placed excludes it — it's a lower bound on order date.
    $fromAfter = $order->ordered_at->copy()->addDay()->toDateString();
    expect($inventory->dueAgingReport($customer->id, $fromAfter))->toHaveCount(0);
});

it('drives the fromDate filter through the Livewire component', function (): void {
    Gate::define('inventory.reports.view', fn ($user = null): bool => true);

    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create([
        'code'         => 'W-ARDA-3',
        'name'         => 'Aging Report From Warehouse 2',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $customer = Customer::create(['code' => 'CUS-ARDA-3', 'name' => 'Livewire From Customer', 'organization_name' => 'Livewire From Customer', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);
    $product = Product::create(['sku' => 'SKU-ARDA-3', 'name' => 'Livewire From Widget', 'unit' => 'pcs', 'is_stockable' => true]);

    WarehouseProduct::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'qty_on_hand' => 50, 'wac_amount' => 10]);

    $order = $inventory->createSaleOrder([
        'warehouse_id' => $warehouse->id, 'customer_id' => $customer->id, 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail',
        'items' => [['product_id' => $product->id, 'qty_ordered' => 1, 'unit_price_local' => 100]],
    ]);
    $inventory->confirmSaleOrder($order->id);
    $order->update(['ordered_at' => now()->subDays(5)]);

    // Setting fromDate after the order was placed removes the customer from the table.
    Livewire::test(AgingReportPage::class)
        ->assertSee('Livewire From Customer')
        ->set('fromDate', $order->ordered_at->copy()->addDay()->toDateString())
        ->assertDontSee('Livewire From Customer');
});

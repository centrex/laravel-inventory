<?php

declare(strict_types = 1);

use Centrex\Inventory\Enums\SaleOrderStatus;
use Centrex\Inventory\Http\Livewire\Transactions\{InventoryDraftSaleOrdersCard, InventoryForecastCard, InventorySalesByEmployeeCard, InventorySalesByPriceTierCard, InventorySalesTargetCard, InventorySalesTrendCard, InventoryWarehouseStockCard};
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Customer, Product, SaleOrder, Warehouse, WarehouseProduct};
use Illuminate\Support\Facades\{DB, Gate};

beforeEach(function (): void {
    Gate::define('inventory.reports.view', fn ($user = null): bool => true);
    Gate::define('inventory.master-data.view', fn ($user = null): bool => true);
    config()->set('cache.default', 'array');
});

it('InventoryForecastCard computes a forecast and caches the result', function (): void {
    $component = new InventoryForecastCard;
    $component->mount();

    $forecast = $component->forecast();

    expect($forecast)->toHaveKeys(['window', 'summary', 'products', 'customers', 'timeline']);

    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    $component->forecast();

    expect($queryCount)->toBe(0);
});

it('InventorySalesTargetCard reads inputs from the query string and caches the result', function (): void {
    request()->merge(['target_lookback_days' => 60, 'target_days' => 14]);

    $component = new InventorySalesTargetCard;
    $component->mount();

    expect($component->lookbackDays)->toBe(60)
        ->and($component->targetDays)->toBe(14);

    $target = $component->salesTarget();

    expect($target)->toHaveKeys(['window', 'target', 'history', 'inputs', 'availability']);

    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    $component->salesTarget();

    expect($queryCount)->toBe(0);
});

it('InventorySalesTrendCard builds the this/prev-month trend and caches the result', function (): void {
    $component = new InventorySalesTrendCard;
    $component->mount();

    $trend = $component->trend();

    expect($trend)->toHaveKeys(['scope_label', 'this_month', 'prev_month', 'change', 'dispatched_count', 'backlog', 'chart']);

    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    $component->trend();

    expect($queryCount)->toBe(0);
});

it('InventorySalesTrendCard surfaces orders stuck awaiting fulfillment as a stale backlog', function (): void {
    $warehouse = Warehouse::create([
        'code' => 'W-TREND-1', 'name' => 'Trend Backlog Warehouse', 'country_code' => 'BD', 'currency' => 'BDT',
    ]);
    $customer = Customer::create(['code' => 'CUS-TREND-1', 'name' => 'Trend Backlog Customer', 'organization_name' => 'Trend Backlog Customer', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);
    $product = Product::create(['sku' => 'SKU-TREND-1', 'name' => 'Trend Backlog Widget', 'unit' => 'pcs', 'is_stockable' => true]);
    WarehouseProduct::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'qty_on_hand' => 50, 'qty_reserved' => 10, 'wac_amount' => 10]);

    // A stale order: reserved (Processing) for 5 days with no cogs_amount — never fulfilled.
    $stale = app(Inventory::class)->createSaleOrder([
        'warehouse_id' => $warehouse->id, 'customer_id' => $customer->id, 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail',
        'items'        => [['product_id' => $product->id, 'qty_ordered' => 5, 'unit_price_local' => 150]],
    ]);
    $stale->forceFill(['status' => SaleOrderStatus::PROCESSING, 'ordered_at' => now()->subDays(5)])->save();

    // A fresh order: also reserved but placed today — not stale yet.
    $fresh = app(Inventory::class)->createSaleOrder([
        'warehouse_id' => $warehouse->id, 'customer_id' => $customer->id, 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail',
        'items'        => [['product_id' => $product->id, 'qty_ordered' => 3, 'unit_price_local' => 150]],
    ]);
    $fresh->forceFill(['status' => SaleOrderStatus::PROCESSING])->save();

    $component = new InventorySalesTrendCard;
    $component->mount();

    $backlog = $component->trend()['backlog'];

    expect($backlog['pending_count'])->toBe(2)
        ->and($backlog['stale_count'])->toBe(1)
        ->and($backlog['oldest_days'])->toBe(5)
        ->and($backlog['pending_value'])->toBe(round(5 * 150 + 3 * 150, 2));
});

it('InventoryDraftSaleOrdersCard lists pending draft orders and caches the result', function (): void {
    $component = new InventoryDraftSaleOrdersCard;
    $component->mount();

    $draftSaleOrders = $component->draftSaleOrders();

    expect($draftSaleOrders)->toHaveKeys(['count', 'total', 'recent']);

    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    $component->draftSaleOrders();

    expect($queryCount)->toBe(0);
});

it('InventoryDraftSaleOrdersCard never caches SaleOrder models — only plain arrays', function (): void {
    // The 'array' cache store used in these tests keeps values in-process without ever
    // calling serialize()/unserialize(), so it can't catch this: in production
    // (file/redis/database stores), a SaleOrder model surviving into the cached 'recent'
    // list comes back as __PHP_Incomplete_Class on the next request if the SaleOrder
    // class isn't autoloaded yet, and ->so_number/->customer throw. Assert the invariant
    // directly instead.
    $warehouse = Warehouse::create([
        'code' => 'W-DSO-1', 'name' => 'Draft Sale Orders Warehouse', 'country_code' => 'BD', 'currency' => 'BDT',
    ]);
    $customer = Customer::create(['code' => 'CUS-DSO-1', 'name' => 'Draft Orders Customer', 'organization_name' => 'Draft Orders Customer', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);
    $product = Product::create(['sku' => 'SKU-DSO-1', 'name' => 'Draft Orders Widget', 'unit' => 'pcs', 'is_stockable' => true]);
    WarehouseProduct::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'qty_on_hand' => 50, 'wac_amount' => 10]);

    app(Inventory::class)->createSaleOrder([
        'warehouse_id' => $warehouse->id, 'customer_id' => $customer->id, 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail',
        'items'        => [['product_id' => $product->id, 'qty_ordered' => 1, 'unit_price_local' => 150]],
    ]);

    $component = new InventoryDraftSaleOrdersCard;
    $component->mount();

    $recent = $component->draftSaleOrders()['recent'];

    expect($recent)->toBeArray()->not->toBeEmpty();

    foreach ($recent as $row) {
        expect($row)->toBeArray()
            ->and($row)->not->toBeInstanceOf(SaleOrder::class)
            ->and($row['customer_name'])->toBe('Draft Orders Customer');
    }
});

it('InventorySalesByPriceTierCard breaks down revenue by tier and caches the result', function (): void {
    $component = new InventorySalesByPriceTierCard;
    $component->mount();

    expect($component->salesByPriceTier())->toBeArray();

    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    $component->salesByPriceTier();

    expect($queryCount)->toBe(0);
});

it('InventorySalesByEmployeeCard breaks down revenue and gross profit by employee and caches the result', function (): void {
    $component = new InventorySalesByEmployeeCard;
    $component->mount();

    expect($component->salesByEmployee())->toBeArray();

    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    $component->salesByEmployee();

    expect($queryCount)->toBe(0);
});

it('InventoryWarehouseStockCard aggregates stock value/net saleable stock and caches the result', function (): void {
    $component = new InventoryWarehouseStockCard;
    $component->mount();

    $warehouseStock = $component->warehouseStock();

    expect($warehouseStock)->toHaveKeys(['warehouses', 'total_stock_value', 'total_net_saleable_stock']);

    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    $component->warehouseStock();

    expect($queryCount)->toBe(0);
});

it('InventoryWarehouseStockCard::refresh() busts the cache so the next call recomputes', function (): void {
    $component = new InventoryWarehouseStockCard;
    $component->mount();

    $component->warehouseStock();

    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    $component->refresh();
    $component->warehouseStock();

    expect($queryCount)->toBeGreaterThan(0);
});

it('InventoryWarehouseStockCard never caches the warehouses Collection itself — only a plain array', function (): void {
    // The 'array' cache store used in these tests keeps values in-process without ever
    // calling serialize()/unserialize(), so it can't catch this: in production
    // (file/redis/database stores), a Collection surviving into the cached 'warehouses'
    // value comes back wrong on the next request if its class isn't autoloaded yet by the
    // time unserialize() runs, and the view throws deep inside the loop with no obvious
    // link back to the cache.
    $warehouse = Warehouse::create([
        'code' => 'W-WSC-1', 'name' => 'Warehouse Stock Card Warehouse', 'country_code' => 'BD', 'currency' => 'BDT',
    ]);
    $product = Product::create(['sku' => 'SKU-WSC-1', 'name' => 'Warehouse Stock Card Widget', 'unit' => 'pcs', 'is_stockable' => true]);
    WarehouseProduct::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'qty_on_hand' => 50, 'wac_amount' => 10]);

    $component = new InventoryWarehouseStockCard;
    $component->mount();

    $warehouses = $component->warehouseStock()['warehouses'];

    expect($warehouses)->toBeArray()->not->toBeEmpty();

    foreach ($warehouses as $row) {
        expect($row)->toBeArray()->and($row)->toHaveKey('name');
    }
});

it('the dashboard blade only mounts the forecast/target cards behind an Alpine x-if for their own tab', function (): void {
    $blade = file_get_contents(__DIR__ . '/../../resources/views/dashboard.blade.php');

    expect($blade)
        ->toContain('<template x-if="activeTab === \'forecast\'">')
        ->toContain('<livewire:inventory-forecast-card lazy />')
        ->toContain('<template x-if="activeTab === \'target\'">')
        ->toContain('<livewire:inventory-sales-target-card lazy />');
});

it('the dashboard blade mounts the overview report cards as plain lazy Livewire components', function (): void {
    $blade = file_get_contents(__DIR__ . '/../../resources/views/dashboard.blade.php');

    expect($blade)
        ->toContain('<livewire:inventory-sales-trend-card lazy />')
        ->toContain('<livewire:inventory-draft-sale-orders-card lazy />')
        ->toContain('<livewire:inventory-sales-by-price-tier-card lazy />')
        ->toContain('<livewire:inventory-sales-by-employee-card lazy />')
        ->toContain('<livewire:inventory-warehouse-stock-card lazy />');
});

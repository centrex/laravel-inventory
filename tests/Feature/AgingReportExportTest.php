<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Transactions\{InventoryDueAgingCard, InventoryStockAgingCard};
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Customer, Product, Warehouse, WarehouseProduct};
use Illuminate\Support\Facades\Gate;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function (): void {
    Gate::define('inventory.reports.view', fn ($user = null): bool => true);
});

function loadAgingExportSpreadsheet(Symfony\Component\HttpFoundation\StreamedResponse $response): PhpOffice\PhpSpreadsheet\Spreadsheet
{
    $tmpFile = tempnam(sys_get_temp_dir(), 'aging-export');

    ob_start();
    $response->sendContent();
    file_put_contents($tmpFile, ob_get_clean());

    $spreadsheet = IOFactory::load($tmpFile);
    @unlink($tmpFile);

    return $spreadsheet;
}

it('exports the stock aging tab as its own .xlsx respecting the warehouse filter', function (): void {
    $warehouse = Warehouse::create([
        'code'         => 'W-ARE-1',
        'name'         => 'Aging Export Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $product = Product::create(['sku' => 'SKU-ARE-1', 'name' => 'Aging Export Widget', 'unit' => 'pcs', 'is_stockable' => true]);
    WarehouseProduct::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'qty_on_hand' => 50, 'wac_amount' => 10]);

    $component = new InventoryStockAgingCard();
    $component->mount();
    $component->warehouseId = $warehouse->id;

    $response = $component->exportExcel();

    expect($response->headers->get('Content-Type'))
        ->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=UTF-8')
        ->and($response->headers->get('Content-Disposition'))
        ->toContain('stock-aging-')
        ->toContain('.xlsx');

    $spreadsheet = loadAgingExportSpreadsheet($response);

    expect($spreadsheet->getSheetNames())->toBe(['Stock Aging']);

    $sheet = $spreadsheet->getSheetByName('Stock Aging');
    expect($sheet->getCell('A1')->getValue())->toBe('Warehouse')
        ->and($sheet->getCell('C2')->getValue())->toBe('Aging Export Widget');
});

it('exports the due aging tab as its own .xlsx respecting the fromDate filter', function (): void {
    $inventory = app(Inventory::class);
    $warehouse = Warehouse::create([
        'code'         => 'W-ARE-2',
        'name'         => 'Aging Export Warehouse 2',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $customer = Customer::create(['code' => 'CUS-ARE-1', 'name' => 'Aging Export Customer', 'organization_name' => 'Aging Export Customer', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);
    $product = Product::create(['sku' => 'SKU-ARE-2', 'name' => 'Aging Export Widget 2', 'unit' => 'pcs', 'is_stockable' => true]);
    WarehouseProduct::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'qty_on_hand' => 50, 'wac_amount' => 10]);

    $order = $inventory->createSaleOrder([
        'warehouse_id' => $warehouse->id, 'customer_id' => $customer->id, 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail',
        'items'        => [['product_id' => $product->id, 'qty_ordered' => 1, 'unit_price_local' => 250]],
    ]);
    $inventory->confirmSaleOrder($order->id);

    $component = new InventoryDueAgingCard();
    $component->mount();

    $response = $component->exportExcel();

    expect($response->headers->get('Content-Type'))
        ->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=UTF-8')
        ->and($response->headers->get('Content-Disposition'))
        ->toContain('due-aging-')
        ->toContain('.xlsx');

    $spreadsheet = loadAgingExportSpreadsheet($response);

    expect($spreadsheet->getSheetNames())->toBe(['Due Aging']);

    $sheet = $spreadsheet->getSheetByName('Due Aging');
    expect($sheet->getCell('A1')->getValue())->toBe('Customer')
        ->and($sheet->getCell('A2')->getValue())->toBe('Aging Export Customer');
});

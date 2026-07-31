<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Transactions\InventoryReportsPage;
use Illuminate\Support\Facades\Gate;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function (): void {
    Gate::define('inventory.reports.view', fn ($user = null): bool => true);
});

it('exports every report as one multi-sheet workbook with a Summary tab plus one tab per report', function (): void {
    $component = new InventoryReportsPage();
    $component->mount();

    $response = $component->exportAll();

    expect($response->headers->get('Content-Type'))
        ->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=UTF-8')
        ->and($response->headers->get('Content-Disposition'))
        ->toContain('inventory-reports-')
        ->toContain('.xlsx');

    $tmpFile = tempnam(sys_get_temp_dir(), 'inventory-reports-export');

    ob_start();
    $response->sendContent();
    file_put_contents($tmpFile, ob_get_clean());

    $spreadsheet = IOFactory::load($tmpFile);
    @unlink($tmpFile);

    expect($spreadsheet->getSheetNames())->toBe([
        'Summary',
        'Sales',
        'Sales Products',
        'Purchase',
        'Purchase Products',
        'Stock Valuation',
        'Low Stock',
        'Stock Aging',
        'Due Aging',
        'Forecast Products',
        'Forecast Customers',
    ]);

    // Header row of the Sales sheet — sanity check the exporter wrote real headers, not just
    // an empty workbook shell.
    $salesSheet = $spreadsheet->getSheetByName('Sales');
    expect($salesSheet->getCell('A1')->getValue())->toBe('SO Number')
        ->and($salesSheet->getCell('J1')->getValue())->toBe('Total');
});

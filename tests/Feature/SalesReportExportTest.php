<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Transactions\SalesReportPage;
use Illuminate\Support\Facades\Gate;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function (): void {
    Gate::define('inventory.reports.view', fn ($user = null): bool => true);
});

it('exports the sales report as one workbook with a tab per sale report section', function (): void {
    $page = new SalesReportPage;
    $page->mount();

    $response = $page->exportExcel();

    expect($response->headers->get('Content-Type'))
        ->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=UTF-8')
        ->and($response->headers->get('Content-Disposition'))
        ->toContain('sales-report-')
        ->toContain('.xlsx');

    $tmpFile = tempnam(sys_get_temp_dir(), 'sales-report-export');

    ob_start();
    $response->sendContent();
    file_put_contents($tmpFile, ob_get_clean());

    $spreadsheet = IOFactory::load($tmpFile);
    @unlink($tmpFile);

    expect($spreadsheet->getSheetNames())->toBe(['Sale Statistics', 'Recent Sales', 'Sold Products']);

    $statistics = $spreadsheet->getSheetByName('Sale Statistics');
    expect($statistics->getCell('A1')->getValue())->toBe('Metric')
        ->and($statistics->getCell('A2')->getValue())->toBe('Orders');

    $recentSales = $spreadsheet->getSheetByName('Recent Sales');
    expect($recentSales->getCell('A1')->getValue())->toBe('SO Number');

    $soldProducts = $spreadsheet->getSheetByName('Sold Products');
    expect($soldProducts->getCell('A1')->getValue())->toBe('Product');
});

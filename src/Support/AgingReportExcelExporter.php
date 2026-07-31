<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Support;

use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports the aging report data as .xlsx — used independently by InventoryStockAgingCard
 * and InventoryDueAgingCard (each tab of AgingReportPage lazy-loads and owns its own
 * filters, so each exports just its own sheet rather than one combined workbook).
 */
class AgingReportExcelExporter
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows  as returned by Inventory::stockAgingReport()
     */
    public static function downloadStockAging(Collection $rows, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        self::writeStockAgingSheet($spreadsheet, 0, $rows);

        return self::stream($spreadsheet, $filename);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows  as returned by InventoryDueAgingCard::groupDueAgingByCustomer()
     */
    public static function downloadDueAging(Collection $rows, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        self::writeDueAgingSheet($spreadsheet, 0, $rows);

        return self::stream($spreadsheet, $filename);
    }

    private static function stream(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        return response()->streamDownload(
            static function () use ($spreadsheet): void {
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
            },
            $filename,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private static function writeStockAgingSheet(Spreadsheet $spreadsheet, int $index, Collection $rows): void
    {
        $sheetRows = $rows->map(function (array $row): array {
            /** @var array<string, array{qty: float, value: float}> $buckets */
            $buckets = $row['buckets'];

            return [
                $row['warehouse'], $row['sku'], $row['product'],
                $row['qty_on_hand'], $row['wac_amount'], $row['total_value_amount'], $row['oldest_days_in_stock'],
                $buckets['0-30']['qty'], $buckets['0-30']['value'],
                $buckets['31-60']['qty'], $buckets['31-60']['value'],
                $buckets['61-90']['qty'], $buckets['61-90']['value'],
                $buckets['90+']['qty'], $buckets['90+']['value'],
                $buckets['unknown']['qty'], $buckets['unknown']['value'],
            ];
        })->all();

        self::writeSheet($spreadsheet, $index, 'Stock Aging', [
            'Warehouse', 'SKU', 'Product', 'Qty On Hand', 'WAC', 'Total Value', 'Oldest Days',
            '0-30 Qty', '0-30 Value', '31-60 Qty', '31-60 Value', '61-90 Qty', '61-90 Value', '90+ Qty', '90+ Value', 'Unknown Qty', 'Unknown Value',
        ], $sheetRows);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private static function writeDueAgingSheet(Spreadsheet $spreadsheet, int $index, Collection $rows): void
    {
        $sheetRows = $rows->map(function (array $row): array {
            /** @var array<string, float> $buckets */
            $buckets = $row['buckets'];

            return [
                $row['customer'], $row['orders_count'], $row['oldest_days_overdue'],
                $buckets['0-30'], $buckets['31-60'], $buckets['61-90'], $buckets['90+'], $row['total_due'],
            ];
        })->all();

        self::writeSheet($spreadsheet, $index, 'Due Aging', [
            'Customer', 'Orders', 'Oldest Days Overdue', '0-30', '31-60', '61-90', '90+', 'Total Due',
        ], $sheetRows);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    private static function writeSheet(Spreadsheet $spreadsheet, int $index, string $title, array $headers, array $rows): Worksheet
    {
        $sheet = $index === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
        $sheet->setTitle($title);
        $sheet->fromArray([$headers, ...$rows], null, 'A1');

        $lastColumn = $sheet->getHighestColumn();
        $headerRange = "A1:{$lastColumn}1";
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
        $sheet->freezePane('A2');

        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return $sheet;
    }
}

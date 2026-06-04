<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Support;

use Centrex\Inventory\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierExporter
{
    /**
     * @param  Collection<int, Supplier>  $records
     */
    public static function download(Collection $records, string $filename): StreamedResponse
    {
        return response()->streamDownload(
            static function () use ($records): void {
                echo self::renderWorkbook($records);
            },
            $filename,
            [
                'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            ],
        );
    }

    /**
     * @param  Collection<int, Supplier>  $records
     */
    private static function renderWorkbook(Collection $records): string
    {
        $rows = [
            self::row([
                'Code',
                'Name',
                'Country',
                'Contact Name',
                'Contact Email',
                'Contact Phone',
                'Segment',
                'Currency',
                'Active',
                'Purchase Manager',
                'Total POs',
                'POs (Last 30d)',
                'Purchase Value (Last 30d)',
                'Last PO Date',
            ], true),
        ];

        foreach ($records as $record) {
            $record->loadMissing(['purchaseManager']);

            $rows[] = self::row([
                $record->code ?? '',
                $record->name ?? '',
                $record->country_code ?? '',
                $record->contact_name ?? '',
                $record->contact_email ?? '',
                $record->contact_phone ?? '',
                $record->demographic_segment ?? '',
                $record->currency ?? '',
                $record->is_active ? 'Yes' : 'No',
                $record->purchaseManager?->name ?? '',
                (int) ($record->po_count_total ?? 0),
                (int) ($record->po_count_last_month ?? 0),
                number_format((float) ($record->po_value_last_month ?? 0), 2, '.', ''),
                $record->last_po_date ? Carbon::parse($record->last_po_date)->format('Y-m-d') : '',
            ]);
        }

        return '<html><head><meta charset="UTF-8"></head><body><table border="1">' . implode('', $rows) . '</table></body></html>';
    }

    /**
     * @param  array<int, mixed>  $columns
     */
    private static function row(array $columns, bool $heading = false): string
    {
        $tag = $heading ? 'th' : 'td';

        return '<tr>' . collect($columns)
            ->map(fn ($column): string => '<' . $tag . '>' . e((string) ($column ?? '')) . '</' . $tag . '>')
            ->implode('') . '</tr>';
    }
}

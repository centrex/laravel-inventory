<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Support;

use Centrex\Inventory\Models\Customer;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerExporter
{
    /**
     * @param  Collection<int, Customer>  $records
     * @param  array<int, array{clv_12m?: float, prob_alive?: float, expected_tx?: float}>  $clvData  keyed by customer id
     */
    public static function download(Collection $records, string $filename, array $clvData = []): StreamedResponse
    {
        return response()->streamDownload(
            static function () use ($records, $clvData): void {
                echo self::renderWorkbook($records, $clvData);
            },
            $filename,
            [
                'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            ],
        );
    }

    /**
     * @param  Collection<int, Customer>  $records
     * @param  array<int, array{clv_12m?: float, prob_alive?: float, expected_tx?: float}>  $clvData
     */
    private static function renderWorkbook(Collection $records, array $clvData = []): string
    {
        $hasClv = $clvData !== [];

        $headers = [
            'Code', 'Name', 'Organization', 'Email', 'Phone',
            'Zone', 'Area', 'Segment', 'Currency', 'Credit Limit',
            'Price Tier', 'Active', 'Sales Owner',
            'Total Sales', 'Sales (Last 30d)', 'Sale Value (Last 30d)', 'Last Sale Date',
        ];

        if ($hasClv) {
            $headers[] = 'CLV (12m)';
            $headers[] = 'P(Alive) %';
            $headers[] = 'Exp. Tx (12m)';
        }

        $rows = [self::row($headers, true)];

        foreach ($records as $record) {
            $record->loadMissing(['salesOwner']);

            $clv = $clvData[$record->getKey()] ?? [];

            $row = [
                $record->code ?? '',
                $record->name ?? '',
                $record->organization_name ?? '',
                $record->email ?? '',
                $record->phone ?? '',
                $record->zone ?? '',
                $record->area ?? '',
                $record->demographic_segment ?? '',
                $record->currency ?? '',
                number_format((float) $record->credit_limit_amount, 2, '.', ''),
                $record->price_tier_code ?? '',
                $record->is_active ? 'Yes' : 'No',
                $record->salesOwner?->name ?? '',
                (int) ($record->sale_count_total ?? 0),
                (int) ($record->sale_count_last_month ?? 0),
                number_format((float) ($record->sale_value_last_month ?? 0), 2, '.', ''),
                $record->last_sale_date ? \Illuminate\Support\Carbon::parse($record->last_sale_date)->format('Y-m-d') : '',
            ];

            if ($hasClv) {
                $row[] = isset($clv['clv_12m']) ? number_format((float) $clv['clv_12m'], 2, '.', '') : '';
                $row[] = isset($clv['prob_alive']) ? number_format($clv['prob_alive'] * 100, 1) . '%' : '';
                $row[] = $clv['expected_tx'] ?? '';
            }

            $rows[] = self::row($row);
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

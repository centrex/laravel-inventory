<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Support;

use Centrex\Inventory\Models\WarehouseProduct;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WarehouseStockExporter
{
    /**
     * @param  Collection<int, WarehouseProduct>  $records
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
     * @param  Collection<int, WarehouseProduct>  $records
     */
    private static function renderWorkbook(Collection $records): string
    {
        $rows = [
            self::row([
                'Warehouse',
                'Product',
                'SKU',
                'Qty On Hand',
                'Qty Reserved',
                'Qty In Transit',
                'Qty Available',
                'WAC Amount',
                'Total Value',
                'Reorder Point',
                'Bin Location',
            ], true),
        ];

        foreach ($records as $record) {
            $record->loadMissing(['warehouse', 'product', 'variant']);

            $productName = $record->product?->name ?? '';
            $variantSuffix = $record->variant !== null
                ? ' / ' . ($record->variant->sku ?: $record->variant->name ?: '')
                : '';
            $sku = $record->variant?->sku ?? $record->product?->sku ?? '';

            $rows[] = self::row([
                $record->warehouse?->name,
                $productName . $variantSuffix,
                $sku,
                self::decimal($record->qty_on_hand),
                self::decimal($record->qty_reserved),
                self::decimal($record->qty_in_transit),
                self::decimal($record->qtyAvailable()),
                self::decimal($record->wac_amount),
                self::decimal($record->totalValue()),
                $record->reorder_point !== null ? self::decimal($record->reorder_point) : '',
                $record->bin_location ?? '',
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

    private static function decimal(mixed $value, int $precision = 4): string
    {
        return number_format((float) $value, $precision, '.', '');
    }
}

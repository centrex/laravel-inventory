<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Support;

use Centrex\Inventory\Models\Shipment;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShipmentExcelExporter
{
    /**
     * @param  Collection<int, Shipment>  $shipments
     */
    public static function download(Collection $shipments, string $filename): StreamedResponse
    {
        return response()->streamDownload(
            static function () use ($shipments): void {
                echo self::renderWorkbook($shipments);
            },
            $filename,
            [
                'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            ],
        );
    }

    /**
     * @param  Collection<int, Shipment>  $shipments
     */
    private static function renderWorkbook(Collection $shipments): string
    {
        $rows = [
            self::row([
                'Shipment Number',
                'Status',
                'From Warehouse',
                'To Warehouse',
                'Shipment Weight KG',
                'Shipping Cost',
                'Shipped At',
                'Received At',
                'Box Code',
                'Box Weight KG',
                'Box Notes',
                'Product',
                'Variant',
                'Qty Sent',
                'Theoretical Weight KG',
                'Allocated Weight KG',
                'Weight Ratio',
                'Source Unit Cost',
                'Shipping Allocated',
                'Unit Landed Cost',
                'Item Notes',
            ], true),
        ];

        foreach ($shipments as $shipment) {
            $shipment->loadMissing(['fromWarehouse', 'toWarehouse', 'items.product', 'items.variant', 'boxes.items.product', 'boxes.items.variant']);

            if ($shipment->boxes->isEmpty()) {
                $rows[] = self::row(array_merge(self::baseColumns($shipment), array_fill(0, 13, null)));

                continue;
            }

            foreach ($shipment->boxes as $box) {
                if ($box->items->isEmpty()) {
                    $rows[] = self::row(array_merge(self::baseColumns($shipment), [
                        $box->box_code,
                        self::decimal($box->measured_weight_kg),
                        $box->notes,
                    ], array_fill(0, 10, null)));

                    continue;
                }

                foreach ($box->items as $item) {
                    $rows[] = self::row(array_merge(self::baseColumns($shipment), [
                        $box->box_code,
                        self::decimal($box->measured_weight_kg),
                        $box->notes,
                        $item->product?->name,
                        $item->variant?->name ?? $item->variant?->sku,
                        self::decimal($item->qty_sent),
                        self::decimal($item->theoretical_weight_kg),
                        self::decimal($item->allocated_weight_kg),
                        self::decimal($item->weight_ratio, 8),
                        self::decimal($item->source_unit_cost_amount),
                        self::decimal($item->shipping_allocated_amount),
                        self::decimal($item->unit_landed_cost_amount),
                        $item->notes,
                    ]));
                }
            }
        }

        return '<html><head><meta charset="UTF-8"></head><body><table border="1">' . implode('', $rows) . '</table></body></html>';
    }

    /**
     * @return array<int, mixed>
     */
    private static function baseColumns(Shipment $shipment): array
    {
        return [
            $shipment->shipment_number,
            $shipment->status?->label() ?? ucfirst((string) $shipment->status),
            $shipment->fromWarehouse?->name,
            $shipment->toWarehouse?->name,
            self::decimal($shipment->total_weight_kg),
            self::decimal($shipment->shipping_cost_amount),
            $shipment->shipped_at?->format('Y-m-d H:i:s'),
            $shipment->received_at?->format('Y-m-d H:i:s'),
        ];
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

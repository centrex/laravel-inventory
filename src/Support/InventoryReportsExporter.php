<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Support;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Product, ProductVariant, PurchaseOrder, PurchaseOrderItem, SaleOrder, SaleOrderItem, WarehouseProduct};
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Combines every /inventory/reports page (Sales, Purchase, Stock, Aging, Forecast) into a
 * single multi-sheet .xlsx: a Summary tab plus one detail tab per report, each pulling the
 * *full* matching dataset rather than the capped/paginated rows those Livewire pages render
 * on screen (e.g. SalesReportPage caps "recent orders" at 25 and "sold products" at the top
 * 50 — this exports every matching row instead).
 *
 * Each detail query reuses the same scoping the corresponding report page uses
 * (CommercialTeamAccess::applySalesScope()/applyPurchaseScope(), the same excluded
 * draft/cancelled statuses) so the export numbers agree with what a user sees on that page
 * for the same filters.
 */
final class InventoryReportsExporter
{
    /**
     * @param  array{startDate?: string, endDate?: string, warehouseId?: int, agingFromDate?: string, forecastLookbackDays?: int, forecastDays?: int}  $params
     */
    public static function download(array $params, string $filename): StreamedResponse
    {
        $spreadsheet = (new self)->build($params);

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
     * @param  array{startDate?: string, endDate?: string, warehouseId?: int, agingFromDate?: string, forecastLookbackDays?: int, forecastDays?: int}  $params
     */
    private function build(array $params): Spreadsheet
    {
        $startDate = $params['startDate'] ?? now()->startOfMonth()->toDateString();
        $endDate = $params['endDate'] ?? now()->toDateString();
        $warehouseId = $params['warehouseId'] ?? null;
        $agingFromDate = $params['agingFromDate'] ?? now()->subDays(365)->toDateString();
        $forecastLookbackDays = $params['forecastLookbackDays'] ?? 90;
        $forecastDays = $params['forecastDays'] ?? 90;

        $inventory = app(Inventory::class);

        $salesOrders = $this->scopedSalesOrders($startDate, $endDate);
        $soldProducts = $this->soldProducts($salesOrders);

        $purchaseOrders = $this->scopedPurchaseOrders($startDate, $endDate);
        $purchasedProducts = $this->purchasedProducts($purchaseOrders);

        $valuation = $inventory->stockValuationReport($warehouseId);
        $lowStock = $inventory->getLowStockItems($warehouseId);
        $stockAging = $inventory->stockAgingReport($warehouseId);
        $dueAging = $inventory->dueAgingReport(fromDate: $agingFromDate);
        $forecast = $inventory->salesForecast(lookbackDays: $forecastLookbackDays, forecastDays: $forecastDays);

        $spreadsheet = new Spreadsheet;

        $this->writeSummarySheet($spreadsheet, 0, [
            'sales'       => $salesOrders,
            'purchase'    => $purchaseOrders,
            'stock_value' => $inventory->getStockValue($warehouseId),
            'low_stock'   => $lowStock,
            'stock_aging' => $stockAging,
            'due_aging'   => $dueAging,
            'forecast'    => $forecast,
            'start_date'  => $startDate,
            'end_date'    => $endDate,
            'aging_from'  => $agingFromDate,
        ]);

        $this->writeSalesSheet($spreadsheet, 1, $salesOrders);
        $this->writeSalesProductsSheet($spreadsheet, 2, $soldProducts);
        $this->writePurchaseSheet($spreadsheet, 3, $purchaseOrders);
        $this->writePurchaseProductsSheet($spreadsheet, 4, $purchasedProducts);
        $this->writeStockValuationSheet($spreadsheet, 5, $valuation);
        $this->writeLowStockSheet($spreadsheet, 6, $lowStock);
        $this->writeStockAgingSheet($spreadsheet, 7, $stockAging);
        $this->writeDueAgingSheet($spreadsheet, 8, $dueAging);
        $this->writeForecastProductsSheet($spreadsheet, 9, collect(data_get($forecast, 'products', [])));
        $this->writeForecastCustomersSheet($spreadsheet, 10, collect(data_get($forecast, 'customers', [])));

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    // -------------------------------------------------------------------------
    // Data — Sales
    // -------------------------------------------------------------------------

    /**
     * @return Collection<int, SaleOrder>
     */
    private function scopedSalesOrders(string $startDate, string $endDate): Collection
    {
        $query = CommercialTeamAccess::applySalesScope(
            SaleOrder::query()->where('document_type', 'order'),
        )
            ->whereDate('ordered_at', '>=', $startDate)
            ->whereDate('ordered_at', '<=', $endDate)
            ->with(['customer', 'warehouse'])
            ->orderBy('ordered_at');

        return $query->get();
    }

    /**
     * @param  Collection<int, SaleOrder>  $orders
     * @return Collection<int, array<string, mixed>>
     */
    private function soldProducts(Collection $orders): Collection
    {
        $orderIds = $orders->reject(fn (SaleOrder $order): bool => in_array($order->status->value, ['draft', 'cancelled'], true))
            ->pluck('id');

        if ($orderIds->isEmpty()) {
            return collect();
        }

        $rows = SaleOrderItem::query()
            ->whereIn('sale_order_id', $orderIds)
            ->selectRaw('product_id, variant_id, SUM(qty_ordered) as qty_sold, SUM(line_total_local) as revenue_local, COUNT(DISTINCT sale_order_id) as orders_count')
            ->groupBy('product_id', 'variant_id')
            ->orderByDesc('qty_sold')
            ->get();

        return $this->resolveProductRows($rows, 'qty_sold', 'revenue_local');
    }

    // -------------------------------------------------------------------------
    // Data — Purchase
    // -------------------------------------------------------------------------

    /**
     * @return Collection<int, PurchaseOrder>
     */
    private function scopedPurchaseOrders(string $startDate, string $endDate): Collection
    {
        $query = CommercialTeamAccess::applyPurchaseScope(
            PurchaseOrder::query()->where('document_type', 'order'),
        )
            ->whereDate('ordered_at', '>=', $startDate)
            ->whereDate('ordered_at', '<=', $endDate)
            ->with(['supplier', 'warehouse'])
            ->orderBy('ordered_at');

        return $query->get();
    }

    /**
     * @param  Collection<int, PurchaseOrder>  $orders
     * @return Collection<int, array<string, mixed>>
     */
    private function purchasedProducts(Collection $orders): Collection
    {
        $orderIds = $orders->reject(fn (PurchaseOrder $order): bool => in_array($order->status->value, ['draft', 'cancelled'], true))
            ->pluck('id');

        if ($orderIds->isEmpty()) {
            return collect();
        }

        $rows = PurchaseOrderItem::query()
            ->whereIn('purchase_order_id', $orderIds)
            ->selectRaw('product_id, variant_id, SUM(qty_ordered) as qty_purchased, SUM(line_total_local) as cost_local, COUNT(DISTINCT purchase_order_id) as orders_count')
            ->groupBy('product_id', 'variant_id')
            ->orderByDesc('qty_purchased')
            ->get();

        return $this->resolveProductRows($rows, 'qty_purchased', 'cost_local');
    }

    /**
     * Shared product/variant name+SKU resolution for the sold/purchased product breakdowns —
     * same shape SalesReportPage::buildSoldProductsReport() / PurchaseReportPage::
     * buildPurchasedProductsReport() use, just without their 50-row display cap.
     *
     * @param  Collection<int, object{product_id: int, variant_id: ?int, orders_count: int}>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function resolveProductRows(Collection $rows, string $qtyKey, string $amountKey): Collection
    {
        $productIds = $rows->pluck('product_id')->filter()->unique();
        $variantIds = $rows->pluck('variant_id')->filter()->unique();

        $products = $productIds->isEmpty()
            ? collect()
            : Product::query()->whereIn('id', $productIds)->get()->keyBy('id');
        $variants = $variantIds->isEmpty()
            ? collect()
            : ProductVariant::query()->whereIn('id', $variantIds)->get()->keyBy('id');

        return $rows->map(function ($row) use ($products, $variants, $qtyKey, $amountKey): array {
            $product = $products->get((int) $row->product_id);
            $variant = $row->variant_id ? $variants->get((int) $row->variant_id) : null;

            return [
                'name'         => $variant?->display_name ?? $product?->name ?? ('Product #' . $row->product_id),
                'sku'          => $variant?->sku ?: $product?->sku,
                'qty'          => round((float) $row->{$qtyKey}, 2),
                'amount'       => round((float) $row->{$amountKey}, 2),
                'orders_count' => (int) $row->orders_count,
            ];
        })->values();
    }

    // -------------------------------------------------------------------------
    // Sheets
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeSummarySheet(Spreadsheet $spreadsheet, int $index, array $data): void
    {
        /** @var Collection<int, SaleOrder> $salesOrders */
        $salesOrders = $data['sales'];
        $billableSales = $salesOrders->reject(fn (SaleOrder $order): bool => in_array($order->status->value, ['draft', 'cancelled'], true));

        /** @var Collection<int, PurchaseOrder> $purchaseOrders */
        $purchaseOrders = $data['purchase'];
        $billablePurchases = $purchaseOrders->reject(fn (PurchaseOrder $order): bool => in_array($order->status->value, ['draft', 'cancelled'], true));

        /** @var Collection<int, WarehouseProduct> $lowStock */
        $lowStock = $data['low_stock'];

        /** @var Collection<int, array<string, mixed>> $stockAging */
        $stockAging = $data['stock_aging'];
        $stockAgingValue = $stockAging->sum('total_value_amount');

        /** @var Collection<int, array<string, mixed>> $dueAging */
        $dueAging = $data['due_aging'];

        $forecast = $data['forecast'];

        $rows = [
            ['Sales', 'Period', $data['start_date'] . ' to ' . $data['end_date']],
            ['Sales', 'Orders', $billableSales->count()],
            ['Sales', 'Net Total', round((float) $billableSales->sum('total_local'), 2)],
            ['Purchase', 'Period', $data['start_date'] . ' to ' . $data['end_date']],
            ['Purchase', 'Orders', $billablePurchases->count()],
            ['Purchase', 'Net Total', round((float) $billablePurchases->sum('total_local'), 2)],
            ['Stock', 'Total Stock Value', round((float) $data['stock_value'], 2)],
            ['Stock', 'Low Stock Items', $lowStock->count()],
            ['Stock Aging', 'Total Value in Stock (Aged)', round((float) $stockAgingValue, 2)],
            ['Due Aging', 'From Date', $data['aging_from']],
            ['Due Aging', 'Total Due', round((float) $dueAging->sum('due_amount'), 2)],
            ['Due Aging', 'Orders Overdue', $dueAging->count()],
            ['Forecast', 'Lookback / Horizon (days)', data_get($forecast, 'window.lookback_days', 0) . ' / ' . data_get($forecast, 'window.forecast_days', 0)],
            ['Forecast', 'Projected Quantity', round((float) data_get($forecast, 'summary.forecast_qty', 0), 2)],
            ['Forecast', 'Projected Revenue', round((float) data_get($forecast, 'summary.forecast_revenue', 0), 2)],
            ['Forecast', 'Products At Risk', data_get($forecast, 'summary.products_at_risk', 0)],
        ];

        $this->writeSheet($spreadsheet, $index, 'Summary', ['Report', 'Metric', 'Value'], $rows);
    }

    /**
     * @param  Collection<int, SaleOrder>  $orders
     */
    private function writeSalesSheet(Spreadsheet $spreadsheet, int $index, Collection $orders): void
    {
        $rows = $orders->map(fn (SaleOrder $order): array => [
            $order->so_number,
            $order->ordered_at?->format('Y-m-d H:i:s'),
            $order->customer?->organization_name ?: $order->customer?->name,
            $order->warehouse?->name,
            $order->status->value,
            round((float) $order->subtotal_local, 2),
            round((float) $order->discount_local, 2),
            round((float) $order->tax_local, 2),
            round((float) $order->shipping_local, 2),
            round((float) $order->total_local, 2),
        ])->all();

        $this->writeSheet($spreadsheet, $index, 'Sales', [
            'SO Number', 'Date', 'Customer', 'Warehouse', 'Status', 'Subtotal', 'Discount', 'Tax', 'Shipping', 'Total',
        ], $rows);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $products
     */
    private function writeSalesProductsSheet(Spreadsheet $spreadsheet, int $index, Collection $products): void
    {
        $rows = $products->map(fn (array $row): array => [
            $row['name'], $row['sku'], $row['qty'], $row['amount'], $row['orders_count'],
        ])->all();

        $this->writeSheet($spreadsheet, $index, 'Sales Products', [
            'Product', 'SKU', 'Qty Sold', 'Revenue', 'Orders',
        ], $rows);
    }

    /**
     * @param  Collection<int, PurchaseOrder>  $orders
     */
    private function writePurchaseSheet(Spreadsheet $spreadsheet, int $index, Collection $orders): void
    {
        $rows = $orders->map(fn (PurchaseOrder $order): array => [
            $order->po_number,
            $order->ordered_at?->format('Y-m-d H:i:s'),
            $order->supplier?->name,
            $order->warehouse?->name,
            $order->status->value,
            round((float) $order->subtotal_local, 2),
            round((float) $order->discount_local, 2),
            round((float) $order->tax_local, 2),
            round((float) $order->shipping_local, 2),
            round((float) $order->other_charges_amount, 2),
            round((float) $order->total_local, 2),
        ])->all();

        $this->writeSheet($spreadsheet, $index, 'Purchase', [
            'PO Number', 'Date', 'Supplier', 'Warehouse', 'Status', 'Subtotal', 'Discount', 'Tax', 'Shipping', 'Other Charges', 'Total',
        ], $rows);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $products
     */
    private function writePurchaseProductsSheet(Spreadsheet $spreadsheet, int $index, Collection $products): void
    {
        $rows = $products->map(fn (array $row): array => [
            $row['name'], $row['sku'], $row['qty'], $row['amount'], $row['orders_count'],
        ])->all();

        $this->writeSheet($spreadsheet, $index, 'Purchase Products', [
            'Product', 'SKU', 'Qty Purchased', 'Cost', 'Orders',
        ], $rows);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $valuation
     */
    private function writeStockValuationSheet(Spreadsheet $spreadsheet, int $index, Collection $valuation): void
    {
        $rows = $valuation->map(fn (array $row): array => [
            $row['warehouse'], $row['sku'], $row['product'], $row['qty_on_hand'], $row['qty_reserved'], $row['qty_available'], $row['wac_amount'], $row['total_value_amount'],
        ])->all();

        $this->writeSheet($spreadsheet, $index, 'Stock Valuation', [
            'Warehouse', 'SKU', 'Product', 'Qty On Hand', 'Qty Reserved', 'Qty Available', 'WAC', 'Total Value',
        ], $rows);
    }

    /**
     * @param  Collection<int, WarehouseProduct>  $items
     */
    private function writeLowStockSheet(Spreadsheet $spreadsheet, int $index, Collection $items): void
    {
        $rows = $items->map(fn (WarehouseProduct $item): array => [
            $item->warehouse?->name,
            $item->product?->sku,
            $item->product?->name,
            (float) $item->qty_on_hand,
            (float) $item->qty_reserved,
            $item->qtyAvailable(),
            $item->reorder_point !== null ? (float) $item->reorder_point : null,
        ])->all();

        $this->writeSheet($spreadsheet, $index, 'Low Stock', [
            'Warehouse', 'SKU', 'Product', 'Qty On Hand', 'Qty Reserved', 'Qty Available', 'Reorder Point',
        ], $rows);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $aging
     */
    private function writeStockAgingSheet(Spreadsheet $spreadsheet, int $index, Collection $aging): void
    {
        $rows = $aging->map(function (array $row): array {
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

        $this->writeSheet($spreadsheet, $index, 'Stock Aging', [
            'Warehouse', 'SKU', 'Product', 'Qty On Hand', 'WAC', 'Total Value', 'Oldest Days',
            '0-30 Qty', '0-30 Value', '31-60 Qty', '31-60 Value', '61-90 Qty', '61-90 Value', '90+ Qty', '90+ Value', 'Unknown Qty', 'Unknown Value',
        ], $rows);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $dueAging
     */
    private function writeDueAgingSheet(Spreadsheet $spreadsheet, int $index, Collection $dueAging): void
    {
        $rows = $dueAging->map(fn (array $row): array => [
            $row['customer'],
            $row['so_number'],
            $row['ordered_at']?->format('Y-m-d'),
            round((float) $row['due_amount'], 2),
            $row['days_overdue'],
            $row['age_bucket'],
        ])->all();

        $this->writeSheet($spreadsheet, $index, 'Due Aging', [
            'Customer', 'SO Number', 'Order Date', 'Due Amount', 'Days Overdue', 'Age Bucket',
        ], $rows);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $products
     */
    private function writeForecastProductsSheet(Spreadsheet $spreadsheet, int $index, Collection $products): void
    {
        $rows = $products->map(fn (array $row): array => [
            $row['product_name'] ?? null,
            $row['sku'] ?? null,
            round((float) ($row['forecast_qty'] ?? 0), 2),
            round((float) ($row['available_soon_qty'] ?? 0), 2),
            round((float) ($row['forecast_gap_qty'] ?? 0), 2),
            $row['days_of_cover'] !== null ? round((float) $row['days_of_cover'], 1) : null,
            $row['stockout_date'] ?? null,
        ])->all();

        $this->writeSheet($spreadsheet, $index, 'Forecast Products', [
            'Product', 'SKU', 'Forecast Qty', 'Available Soon', 'Gap', 'Days of Cover', 'Stockout Date',
        ], $rows);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $customers
     */
    private function writeForecastCustomersSheet(Spreadsheet $spreadsheet, int $index, Collection $customers): void
    {
        $rows = $customers->map(fn (array $row): array => [
            $row['customer_name'] ?? null,
            $row['zone'] ?? null,
            $row['area'] ?? null,
            $row['demographic'] ?? null,
            $row['segment'] ?? null,
            $row['orders_count'] ?? 0,
            $row['products_count'] ?? 0,
            round((float) ($row['forecast_qty'] ?? 0), 2),
            round((float) ($row['forecast_revenue'] ?? 0), 2),
        ])->all();

        $this->writeSheet($spreadsheet, $index, 'Forecast Customers', [
            'Customer', 'Zone', 'Area', 'Demographic', 'Segment', 'Orders', 'Products', 'Forecast Qty', 'Forecast Revenue',
        ], $rows);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function writeSheet(Spreadsheet $spreadsheet, int $index, string $title, array $headers, array $rows): Worksheet
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

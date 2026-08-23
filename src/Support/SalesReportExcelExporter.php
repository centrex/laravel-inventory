<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Support;

use Centrex\Inventory\Http\Livewire\Transactions\Concerns\ScopesSalesReport;
use Centrex\Inventory\Http\Livewire\Transactions\InventorySalesStatisticsCard;
use Centrex\Inventory\Models\{Product, ProductVariant, SaleOrder, SaleOrderItem};
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports SalesReportPage's three tabs (Sale Statistics / Recent Sales / Sold Products) as
 * one .xlsx, scoped to the same date/customer/product filters as the on-screen report — via
 * ScopesSalesReport, the same trait the three Livewire cards use, so the export's totals
 * agree with what the page shows for the same filters.
 *
 * "Recent Sales" and "Sold Products" pull the *full* matching dataset rather than the
 * capped on-screen rows (InventoryRecentSaleOrdersCard caps at 25, InventorySoldProductsCard
 * at 50) — same "export everything" convention as InventoryReportsExporter.
 */
final class SalesReportExcelExporter
{
    use ScopesSalesReport;

    public static function download(string $startDate, string $endDate, ?int $customerId, ?int $productId, ?int $employeeId, string $filename): StreamedResponse
    {
        $exporter = new self;
        $exporter->startDate = $startDate;
        $exporter->endDate = $endDate;
        $exporter->customerId = $customerId;
        $exporter->productId = $productId;
        $exporter->employeeId = $employeeId;

        $spreadsheet = $exporter->build();

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

    private function build(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;

        $this->writeStatisticsSheet($spreadsheet, 0);
        $this->writeRecentSalesSheet($spreadsheet, 1);
        $this->writeSoldProductsSheet($spreadsheet, 2);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * Reuses InventorySalesStatisticsCard's own statistics() — same figures the "Sale
     * Statistics" tab shows — via direct instantiation + reflection rather than going
     * through mount()/mount()'s Gate::authorize() (this exporter is only ever invoked from
     * an action already behind that same gate on SalesReportPage) or CachesData's cache
     * (an export should always reflect the current data, not a stale cached figure).
     */
    private function writeStatisticsSheet(Spreadsheet $spreadsheet, int $index): void
    {
        $card = new InventorySalesStatisticsCard;
        $card->startDate = $this->startDate;
        $card->endDate = $this->endDate;
        $card->customerId = $this->customerId;
        $card->productId = $this->productId;
        $card->employeeId = $this->employeeId;

        $method = new ReflectionMethod($card, 'statistics');
        $method->setAccessible(true);

        /** @var array{salesMetrics: array<string, mixed>, productCount: int} $data */
        $data = $method->invoke($card);
        $metrics = $data['salesMetrics'];

        $rows = [
            ['Orders', $metrics['count']],
            ['Distinct Products Sold', $data['productCount']],
            ['Gross Subtotal', $metrics['gross_subtotal']],
            ['Discount', $metrics['discount']],
            ['Tax', $metrics['tax']],
            ['Shipping', $metrics['shipping']],
            ['Net Total', $metrics['net_total']],
            ['Fulfilled Total', $metrics['fulfilled_total']],
            ['Invoice Paid', $metrics['invoice_paid']],
            ['Invoice Due', $metrics['invoice_due']],
            ['Current Due', $metrics['current_due']],
            ['Historical Due (Overdue)', $metrics['historical_due']],
            ['Overdue Invoices', $metrics['overdue_invoices']],
        ];

        foreach ($metrics['status_counts'] as $status => $count) {
            $rows[] = ['Orders — ' . ucfirst((string) $status), $count];
        }

        self::writeSheet($spreadsheet, $index, 'Sale Statistics', ['Metric', 'Value'], $rows);
    }

    private function writeRecentSalesSheet(Spreadsheet $spreadsheet, int $index): void
    {
        $orders = $this->scopedSalesQuery()
            ->with(['customer', 'warehouse'])
            ->latest('ordered_at')
            ->latest('id')
            ->get();

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

        self::writeSheet($spreadsheet, $index, 'Recent Sales', [
            'SO Number', 'Date', 'Customer', 'Warehouse', 'Status', 'Subtotal', 'Discount', 'Tax', 'Shipping', 'Total',
        ], $rows);
    }

    /** Mirrors InventorySoldProductsCard::buildSoldProductsReport() without its ->limit(50). */
    private function writeSoldProductsSheet(Spreadsheet $spreadsheet, int $index): void
    {
        $orderIds = $this->scopedOrderIds();

        if ($orderIds->isEmpty()) {
            self::writeSheet($spreadsheet, $index, 'Sold Products', [
                'Product', 'SKU', 'Qty Sold', 'Revenue', 'Orders', 'Cost', 'Profit', 'Margin %',
            ], []);

            return;
        }

        $items = SaleOrderItem::query()
            ->whereIn('sale_order_id', $orderIds)
            ->when($this->productId, fn ($query) => $query->where('product_id', $this->productId))
            ->selectRaw('
                product_id, variant_id,
                SUM(qty_ordered) as qty_sold,
                SUM(line_total_local) as revenue_local,
                COUNT(DISTINCT sale_order_id) as orders_count,
                SUM(qty_fulfilled * unit_price_amount) as fulfilled_revenue_amount,
                SUM(qty_fulfilled * unit_cost_amount) as cost_amount
            ')
            ->groupBy('product_id', 'variant_id')
            ->orderByDesc('qty_sold')
            ->get();

        $productIds = $items->pluck('product_id')->filter()->unique();
        $variantIds = $items->pluck('variant_id')->filter()->unique();

        $products = $productIds->isEmpty()
            ? collect()
            : Product::query()->whereIn('id', $productIds)->get()->keyBy('id');
        $variants = $variantIds->isEmpty()
            ? collect()
            : ProductVariant::query()->whereIn('id', $variantIds)->get()->keyBy('id');

        $rows = $items->map(function (SaleOrderItem $row) use ($products, $variants): array {
            $product = $products->get((int) $row->product_id);
            $variant = $row->variant_id ? $variants->get((int) $row->variant_id) : null;

            $fulfilledRevenue = round((float) $row->fulfilled_revenue_amount, 2);
            $cost = round((float) $row->cost_amount, 2);
            $profit = round($fulfilledRevenue - $cost, 2);

            return [
                $product?->name ?? $variant?->display_name ?? ('Product #' . $row->product_id),
                $variant?->sku ?: $product?->sku,
                round((float) $row->qty_sold, 2),
                round((float) $row->revenue_local, 2),
                (int) $row->orders_count,
                $cost,
                $profit,
                $fulfilledRevenue > 0.0 ? round($profit / $fulfilledRevenue * 100, 1) : null,
            ];
        })->all();

        self::writeSheet($spreadsheet, $index, 'Sold Products', [
            'Product', 'SKU', 'Qty Sold', 'Revenue', 'Orders', 'Cost', 'Profit', 'Margin %',
        ], $rows);
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

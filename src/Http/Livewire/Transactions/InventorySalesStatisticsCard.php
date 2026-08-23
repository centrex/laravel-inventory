<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Http\Livewire\Transactions\Concerns\ScopesSalesReport;
use Centrex\Inventory\Models\{SaleOrder, SaleOrderItem};
use Centrex\Inventory\Support\SalesBreakdowns;
use Centrex\TallUi\Concerns\CachesData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Blade, Gate};
use Livewire\Component;

/**
 * Split out of SalesReportPage's "Sale Statistics" tab: the Orders/Net Total/Collected/Due
 * KPI bar, the Sales Totals breakdown card, and employee-wise / price-tier-wise revenue and
 * profit breakdowns (via SalesBreakdowns, shared with the dashboard's own by-employee/
 * by-price-tier cards) — all scoped to the same date/customer/product filters as the
 * Recent Sales and Sold Products tabs (InventoryRecentSaleOrdersCard,
 * InventorySoldProductsCard), via ScopesSalesReport.
 *
 * SalesReportPage re-keys this component (via wire:key) on filter change so a filter change
 * forces a fresh lazy mount rather than being silently ignored — see ScopesSalesReport.
 */
class InventorySalesStatisticsCard extends Component
{
    use CachesData;
    use ScopesSalesReport;

    public function mount(string $startDate = '', string $endDate = '', ?int $customerId = null, ?int $productId = null, ?int $employeeId = null): void
    {
        Gate::authorize('inventory.reports.view');

        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->customerId = $customerId;
        $this->productId = $productId;
        $this->employeeId = $employeeId;
        $this->cacheTtl = 120;
    }

    public function placeholder(): string
    {
        return Blade::render(<<<'BLADE'
            <div role="status" aria-label="Loading sale statistics" class="animate-pulse space-y-6">
                <div class="stats shadow w-full">
                    @for ($i = 0; $i < 5; $i++)
                        <div class="stat"><div class="h-3 w-16 rounded bg-base-300 mb-2"></div><div class="h-6 w-20 rounded bg-base-300"></div></div>
                    @endfor
                </div>
                <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                    <div class="h-64 rounded-2xl border border-base-200 bg-base-100"></div>
                    <div class="h-64 rounded-2xl border border-base-200 bg-base-100"></div>
                </div>
            </div>
            BLADE);
    }

    public function render(): View
    {
        $data = $this->statistics();

        return view('inventory::livewire.transactions.inventory-sales-statistics-card', [
            'salesMetrics'     => $data['salesMetrics'],
            'productCount'     => $data['productCount'],
            'salesByEmployee'  => $data['salesByEmployee'],
            'salesByPriceTier' => $data['salesByPriceTier'],
        ]);
    }

    /** @return array{salesMetrics: array, productCount: int, salesByEmployee: array, salesByPriceTier: array} */
    private function statistics(): array
    {
        return $this->rememberCache(
            $this->cacheKey('inventory', 'sales-statistics-card', $this->startDate, $this->endDate, (string) $this->customerId, (string) $this->productId, (string) $this->employeeId),
            function (): array {
                $orderIds = $this->scopedOrderIds();
                $orders = $orderIds->isEmpty()
                    ? collect()
                    : SaleOrder::query()->whereIn('id', $orderIds)->get(['id', 'price_tier_code', 'sales_executive_id', 'created_by', 'total_amount', 'cogs_amount', 'accounting_invoice_id']);

                return [
                    'salesMetrics'     => $this->buildSalesMetrics($orderIds),
                    'productCount'     => $this->distinctProductCount($orderIds),
                    'salesByEmployee'  => SalesBreakdowns::byEmployee($orders),
                    'salesByPriceTier' => SalesBreakdowns::byPriceTier($orders),
                ];
            },
        );
    }

    private function distinctProductCount(?Collection $orderIds = null): int
    {
        $orderIds ??= $this->scopedOrderIds();

        if ($orderIds->isEmpty()) {
            return 0;
        }

        return (int) SaleOrderItem::query()
            ->whereIn('sale_order_id', $orderIds)
            ->when($this->productId, fn ($query) => $query->where('product_id', $this->productId))
            ->distinct()
            ->count('product_id');
    }

    /**
     * Aggregated over the full scoped date range via SQL SUM()/COUNT() rather than pulling
     * every matching row into a Collection — see SalesReportPage's original docblock for why.
     */
    private function buildSalesMetrics(?Collection $orderIds = null): array
    {
        $orderIds ??= $this->scopedOrderIds();
        $invoiceSummary = $this->invoiceSummary($orderIds);

        $totals = $orderIds->isEmpty()
            ? null
            : SaleOrder::query()
                ->whereIn('id', $orderIds)
                ->toBase()
                ->selectRaw("
                    COUNT(*) as cnt,
                    SUM(subtotal_local) as gross_subtotal,
                    SUM(discount_local) as discount,
                    SUM(tax_local) as tax,
                    SUM(shipping_local) as shipping,
                    SUM(total_local) as net_total,
                    SUM(CASE WHEN status IN ('fulfilled', 'partial') THEN total_local ELSE 0 END) as fulfilled_total
                ")
                ->first();

        $statusCounts = $this->scopedSalesQuery()
            ->toBase()
            ->select('status')
            ->selectRaw('COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->mapWithKeys(fn ($cnt, $status) => [($status ?: 'unknown') => (int) $cnt])
            ->all();

        return [
            'count'            => (int) ($totals->cnt ?? 0),
            'gross_subtotal'   => round((float) ($totals->gross_subtotal ?? 0), 2),
            'discount'         => round((float) ($totals->discount ?? 0), 2),
            'tax'              => round((float) ($totals->tax ?? 0), 2),
            'shipping'         => round((float) ($totals->shipping ?? 0), 2),
            'net_total'        => round((float) ($totals->net_total ?? 0), 2),
            'fulfilled_total'  => round((float) ($totals->fulfilled_total ?? 0), 2),
            'invoice_paid'     => $invoiceSummary['paid'],
            'invoice_due'      => $invoiceSummary['due'],
            'current_due'      => $invoiceSummary['current_due'],
            'historical_due'   => $invoiceSummary['historical_due'],
            'overdue_invoices' => $invoiceSummary['overdue_invoices'],
            'status_counts'    => $statusCounts,
        ];
    }

    /**
     * Invoice paid/due totals from laravel-accounting, scoped to exactly the sale orders
     * matched by the current filters — see SalesReportPage's original docblock for why this
     * can't be pushed into a single SQL SUM().
     *
     * `due` is also split into `current_due` (balance not yet past its invoice due_date, or
     * with no due_date set) and `historical_due` (balance on an invoice whose due_date has
     * already passed, i.e. overdue) — backs the "Collection" card's current-vs-historical
     * breakdown. This is a due-date split, distinct from Inventory::dueAgingReport()'s
     * days-since-order-date buckets used by the Due Aging report.
     */
    private function invoiceSummary(Collection $orderIds): array
    {
        $invoiceClass = \Centrex\Accounting\Models\Invoice::class;

        if (!class_exists($invoiceClass) || $orderIds->isEmpty()) {
            return ['paid' => 0.0, 'due' => 0.0, 'current_due' => 0.0, 'historical_due' => 0.0, 'overdue_invoices' => 0];
        }

        $invoices = $invoiceClass::query()
            ->where(function ($query) use ($orderIds): void {
                $query->where(fn ($q) => $q->where('source_type', SaleOrder::class)->whereIn('source_id', $orderIds))
                    ->orWhereIn('inventory_sale_order_id', $orderIds);
            })
            ->get();

        $today = now()->startOfDay();
        $currentDue = 0.0;
        $historicalDue = 0.0;
        $overdueInvoices = 0;

        foreach ($invoices as $invoice) {
            $balance = round((float) $invoice->base_balance, 2);

            if ($balance <= 0.0) {
                continue;
            }

            if ($invoice->due_date && $invoice->due_date->lt($today)) {
                $historicalDue += $balance;
                $overdueInvoices++;
            } else {
                $currentDue += $balance;
            }
        }

        return [
            'paid'             => round((float) $invoices->sum('base_paid_amount'), 2),
            'due'              => round((float) $invoices->sum('base_balance'), 2),
            'current_due'      => round($currentDue, 2),
            'historical_due'   => round($historicalDue, 2),
            'overdue_invoices' => $overdueInvoices,
        ];
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Enums\SaleOrderStatus;
use Centrex\Inventory\Models\SaleOrder;
use Centrex\Inventory\Support\{CommercialTeamAccess, SalesOrderProfitSummary};
use Centrex\TallUi\Concerns\CachesData;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\{Builder, Collection};
use Illuminate\Support\Facades\{Blade, Gate};
use Livewire\Component;

/**
 * Split out of the inventory dashboard's Overview tab — this-month-vs-prev-month order
 * count/revenue/net-profit plus the daily trend chart, scoped to the current user's sales
 * team via CommercialTeamAccess. Unlike InventoryForecastCard/InventorySalesTargetCard,
 * the Overview tab is visible immediately (not behind an Alpine x-if), so a plain `lazy`
 * attribute is enough to defer this off the initial page response.
 *
 * The cache key is scoped per-user (not just per-class) since CommercialTeamAccess::
 * applySalesScope() resolves visible orders from the *currently authenticated* user —
 * caching without the user id would leak one user's team-scoped figures to another.
 */
class InventorySalesTrendCard extends Component
{
    use CachesData;

    public function mount(): void
    {
        Gate::authorize('inventory.master-data.view');
        $this->cacheTtl = 300;
    }

    public function trend(): array
    {
        return $this->rememberCache(
            $this->cacheKey('inventory', 'sales-trend-card', (string) auth()->id()),
            fn (): array => $this->buildTrend(),
        );
    }

    public function placeholder(): string
    {
        return Blade::render(<<<'BLADE'
            <div role="status" aria-label="Loading sales order trend" class="space-y-6 animate-pulse mb-6">
                <div class="rounded-2xl border border-base-200 bg-base-100 p-5">
                    <div class="h-4 w-48 rounded bg-base-300 mb-5"></div>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4 mb-5">
                        @for ($i = 0; $i < 4; $i++)
                            <div class="h-24 rounded-xl bg-base-200"></div>
                        @endfor
                    </div>
                    <div class="h-48 rounded-xl bg-base-200"></div>
                </div>
            </div>
            BLADE);
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.inventory-sales-trend-card', [
            'salesTrend' => $this->trend(),
        ]);
    }

    private function buildTrend(): array
    {
        $thisStart = now()->startOfMonth();
        $thisEnd = now()->endOfDay();
        $prevStart = now()->subMonthNoOverflow()->startOfMonth();
        $prevEnd = now()->subMonthNoOverflow()->endOfMonth();

        $excluded = [SaleOrderStatus::DRAFT->value, SaleOrderStatus::CANCELLED->value, SaleOrderStatus::RETURNED->value];

        // Scoped to the current user's own orders plus their reporting line (via
        // CommercialTeamAccess), same rule the dispatch terminal and order lists use —
        // an executive sees their own numbers, a manager sees their whole team's.
        $scopedOrders = static fn (): Builder => CommercialTeamAccess::applySalesScope(
            SaleOrder::query()->where('document_type', 'order'),
        );

        $thisOrders = $scopedOrders()
            ->whereBetween('ordered_at', [$thisStart, $thisEnd])
            ->whereNotIn('status', $excluded)
            ->get(['id', 'total_amount', 'cogs_amount', 'accounting_invoice_id', 'ordered_at']);

        $prevOrders = $scopedOrders()
            ->whereBetween('ordered_at', [$prevStart, $prevEnd])
            ->whereNotIn('status', $excluded)
            ->get(['id', 'total_amount', 'cogs_amount', 'accounting_invoice_id', 'ordered_at']);

        $summarizer = app(SalesOrderProfitSummary::class);
        $thisSummary = $summarizer->summarize($thisOrders);
        $prevSummary = $summarizer->summarize($prevOrders);

        $backlog = $this->fulfillmentBacklog($scopedOrders);

        $daysInMonth = now()->daysInMonth;
        $days = range(1, $daysInMonth);

        $fillDaily = static function (Collection $orders) use ($days): array {
            $byDay = array_fill_keys($days, 0.0);

            foreach ($orders->groupBy(static fn (SaleOrder $order): int => (int) ($order->ordered_at?->format('j') ?? 0)) as $day => $group) {
                if (array_key_exists((int) $day, $byDay)) {
                    $byDay[(int) $day] = round((float) $group->sum('total_amount'), 2);
                }
            }

            return array_values($byDay);
        };

        $pctChange = static fn (float $current, float $previous): ?float => $previous != 0.0 ? round(($current - $previous) / abs($previous) * 100, 1) : null;

        // Live dispatch pipeline — orders currently sent to courier (Shipped) and not yet
        // delivered. This is a snapshot, not a monthly comparison, so it isn't part of the
        // this/prev-month trend above.
        $dispatchedCount = $scopedOrders()->where('status', SaleOrderStatus::SHIPPED->value)->count();

        return [
            'scope_label' => CommercialTeamAccess::scopeLabel('sales'),
            'this_month'  => [
                'label'            => now()->format('M Y'),
                'orders_count'     => $thisSummary['orders_count'],
                'revenue'          => $thisSummary['revenue'],
                'gross_profit'     => $thisSummary['gross_profit'],
                'gross_margin_pct' => $thisSummary['gross_margin_pct'],
            ],
            'prev_month' => [
                'label'            => now()->subMonthNoOverflow()->format('M Y'),
                'orders_count'     => $prevSummary['orders_count'],
                'revenue'          => $prevSummary['revenue'],
                'gross_profit'     => $prevSummary['gross_profit'],
                'gross_margin_pct' => $prevSummary['gross_margin_pct'],
            ],
            'change' => [
                'orders_count' => $pctChange($thisSummary['orders_count'], $prevSummary['orders_count']),
                'revenue'      => $pctChange($thisSummary['revenue'], $prevSummary['revenue']),
                'gross_profit' => $pctChange($thisSummary['gross_profit'], $prevSummary['gross_profit']),
            ],
            'dispatched_count' => $dispatchedCount,
            'backlog'          => $backlog,
            'chart'            => [
                'categories' => array_map(static fn (int $d): string => (string) $d, $days),
                'series'     => [
                    ['name' => now()->format('M'), 'data' => $fillDaily($thisOrders)],
                    ['name' => now()->subMonthNoOverflow()->format('M'), 'data' => $fillDaily($prevOrders)],
                ],
            ],
        ];
    }

    /**
     * gross_profit above only counts orders SalesOrderProfitSummary considers "costed"
     * (cogs_amount > 0, set by fulfillSaleOrder()) — an order sitting in Processing/Partial
     * indefinitely never contributes, which reads on this card as gross profit having
     * "stopped updating" even though nothing is actually broken in the calculation. This
     * surfaces that stall directly: not scoped to the this/prev-month window above, since a
     * backlog order can be weeks old and still be silently excluded from every month it
     * touches.
     *
     * @param  \Closure(): Builder  $scopedOrders
     * @return array{pending_count: int, pending_value: float, stale_count: int, oldest_days: ?int}
     */
    private function fulfillmentBacklog(\Closure $scopedOrders): array
    {
        $pendingOrders = $scopedOrders()
            ->whereIn('status', [SaleOrderStatus::PROCESSING->value, SaleOrderStatus::PARTIAL->value])
            ->get(['id', 'total_amount', 'ordered_at']);

        $staleThreshold = now()->subDays(2)->startOfDay();
        $staleOrders = $pendingOrders->filter(
            static fn (SaleOrder $order): bool => $order->ordered_at !== null && $order->ordered_at->lt($staleThreshold),
        );

        $oldest = $staleOrders->min('ordered_at');

        return [
            'pending_count' => $pendingOrders->count(),
            'pending_value' => round((float) $pendingOrders->sum('total_amount'), 2),
            'stale_count'   => $staleOrders->count(),
            'oldest_days'   => $oldest !== null ? (int) $oldest->diffInDays(now()) : null,
        ];
    }
}

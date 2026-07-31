<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Support\AgingReportExcelExporter;
use Centrex\TallUi\Concerns\CachesData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Blade, Gate};
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Split out of AgingReportPage's "Due Aging" tab — see InventoryStockAgingCard's
 * docblock for why these two live in separate lazy-loaded components and read their
 * filter from the request query string instead of #[Url].
 */
class InventoryDueAgingCard extends Component
{
    use CachesData;

    /**
     * Only orders placed on or after this date count toward due aging — a lower bound
     * on which debts show up, not a historical viewpoint. Days-overdue/bucket are still
     * computed relative to today regardless of this value.
     */
    public string $fromDate = '';

    public ?int $agingCustomerId = null;

    public function mount(): void
    {
        Gate::authorize('inventory.reports.view');

        $this->fromDate = request()->filled('from') ? (string) request()->input('from') : now()->subDays(365)->toDateString();
        $this->cacheTtl = 120;
    }

    public function viewCustomerAging(int $customerId): void
    {
        $this->agingCustomerId = $customerId;
        $this->dispatch('open-modal', 'customer-aging-detail');
    }

    public function closeCustomerAgingModal(): void
    {
        $this->agingCustomerId = null;
        $this->dispatch('close-modal', 'customer-aging-detail');
    }

    public function exportExcel(): StreamedResponse
    {
        return AgingReportExcelExporter::downloadDueAging(
            $this->groupDueAgingByCustomer($this->dueAgingOrders()),
            'due-aging-' . now()->format('Ymd-His') . '.xlsx',
        );
    }

    public function placeholder(): string
    {
        return Blade::render(<<<'BLADE'
            <div role="status" aria-label="Loading due aging" class="animate-pulse">
                <div class="rounded-2xl border border-base-200 bg-base-100 p-5">
                    <div class="h-4 w-52 rounded bg-base-300 mb-5"></div>
                    <div class="stats shadow w-full mb-4">
                        @for ($i = 0; $i < 5; $i++)
                            <div class="stat"><div class="h-3 w-16 rounded bg-base-300 mb-2"></div><div class="h-6 w-20 rounded bg-base-300"></div></div>
                        @endfor
                    </div>
                    <div class="h-64 rounded-xl bg-base-200"></div>
                </div>
            </div>
            BLADE);
    }

    public function render(): View
    {
        $inventory = app(Inventory::class);
        $dueAgingOrders = $this->dueAgingOrders();

        return view('inventory::livewire.transactions.inventory-due-aging-card', [
            'fromDate'            => $this->fromDate,
            'dueAgingSummary'     => $inventory->dueAgingSummary(fromDate: $this->fromDate ?: null),
            'customerDueAging'    => $this->groupDueAgingByCustomer($dueAgingOrders),
            'agingCustomerOrders' => $this->agingCustomerId
                ? $dueAgingOrders->filter(fn (array $row): bool => $row['customer_id'] === $this->agingCustomerId)->values()
                : collect(),
            'agingCustomerName' => $this->agingCustomerId
                ? ((string) ($dueAgingOrders->firstWhere('customer_id', $this->agingCustomerId)['customer'] ?? ('Customer #' . $this->agingCustomerId)))
                : null,
        ]);
    }

    /**
     * Caches a plain array, not the Collection itself — the cache store serializes this
     * with PHP's native serialize(), and a Collection surviving into that blob comes back
     * as __PHP_Incomplete_Class if its class isn't autoloaded yet on the next request, so
     * a later iteration throws deep inside the view. Re-wrapped in collect() on the way
     * out so render()/exportExcel()/groupDueAgingByCustomer() keep working with a
     * Collection as before.
     *
     * @return Collection<int, mixed>
     */
    private function dueAgingOrders(): Collection
    {
        return collect($this->rememberCache(
            $this->cacheKey('inventory', 'due-aging-card', $this->fromDate),
            fn (): array => app(Inventory::class)->dueAgingReport(fromDate: $this->fromDate ?: null)
                ->sortByDesc('days_overdue')
                ->values()
                ->all(),
        ));
    }

    /**
     * Due aging orders (from Inventory::dueAgingReport()) grouped into a customer-wise
     * table — one row per customer with their bucket breakdown and total due, sorted by
     * total due descending, instead of one row per order.
     *
     * @param  Collection<int, array<string, mixed>>  $orders
     * @return Collection<int, array<string, mixed>>
     */
    private function groupDueAgingByCustomer(Collection $orders): Collection
    {
        $emptyBuckets = ['0-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '90+' => 0.0, 'unknown' => 0.0];

        return $orders->groupBy('customer_id')
            ->map(function (Collection $customerOrders, $customerId) use ($emptyBuckets): array {
                $buckets = $emptyBuckets;

                foreach ($customerOrders as $order) {
                    $buckets[$order['age_bucket']] += $order['due_amount'];
                }

                return [
                    'customer_id'         => (int) $customerId,
                    'customer'            => $customerOrders->first()['customer'] ?? ('Customer #' . $customerId),
                    'orders_count'        => $customerOrders->count(),
                    'oldest_days_overdue' => $customerOrders->max('days_overdue'),
                    'buckets'             => $buckets,
                    'total_due'           => round((float) $customerOrders->sum('due_amount'), 2),
                ];
            })
            ->sortByDesc('total_due')
            ->values();
    }
}

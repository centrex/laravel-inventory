<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\Warehouse;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\{Layout, Url};
use Livewire\Component;

#[Layout('layouts.app')]
class AgingReportPage extends Component
{
    #[Url(as: 'warehouse', except: '')]
    public ?int $warehouseId = null;

    /**
     * Only orders placed on or after this date count toward due aging — a lower bound
     * on which debts show up, not a historical viewpoint. Days-overdue/bucket are still
     * computed relative to today regardless of this value.
     */
    #[Url(as: 'from', except: '')]
    public string $fromDate = '';

    public ?int $agingCustomerId = null;

    public function mount(): void
    {
        Gate::authorize('inventory.reports.view');
        $this->fromDate = now()->subDays(365)->toDateString();
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

    public function render(): View
    {
        $inventory = app(Inventory::class);
        $warehouses = Warehouse::query()->orderBy('name')->get(['id', 'name']);

        $stockAging = $inventory->stockAgingReport($this->warehouseId)
            ->sortByDesc('oldest_days_in_stock')
            ->values();

        $fromDate = $this->fromDate ?: null;
        $dueAgingOrders = $inventory->dueAgingReport(fromDate: $fromDate)->sortByDesc('days_overdue')->values();

        return view('inventory::livewire.transactions.aging-report', [
            'warehouses'        => $warehouses,
            'stockAging'        => $stockAging,
            'stockAgingSummary' => $inventory->stockAgingSummary($this->warehouseId),
            'dueAgingSummary'   => $inventory->dueAgingSummary(fromDate: $fromDate),
            'customerDueAging'  => $this->groupDueAgingByCustomer($dueAgingOrders),
            'agingCustomerOrders' => $this->agingCustomerId
                ? $dueAgingOrders->filter(fn (array $row): bool => $row['customer_id'] === $this->agingCustomerId)->values()
                : collect(),
            'agingCustomerName' => $this->agingCustomerId
                ? ((string) ($dueAgingOrders->firstWhere('customer_id', $this->agingCustomerId)['customer'] ?? ('Customer #' . $this->agingCustomerId)))
                : null,
        ]);
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

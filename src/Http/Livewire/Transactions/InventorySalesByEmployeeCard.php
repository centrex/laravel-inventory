<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Enums\SaleOrderStatus;
use Centrex\Inventory\Models\SaleOrder;
use Centrex\Inventory\Support\{CommercialTeamAccess, SalesBreakdowns};
use Centrex\TallUi\Concerns\CachesData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\{Blade, Gate};
use Livewire\Component;

/**
 * Split out of the inventory dashboard's Overview tab — this-month revenue/order-count/
 * net-profit/margin broken down by employee (sales executive, falling back to whoever
 * created the order when no executive is tagged).
 *
 * Pulls the full order rows (rather than a SUM() aggregate) so each employee's group can
 * be run back through SalesOrderProfitSummary — the same net-of-COGS/discounts/charges
 * profit calculation InventorySalesTrendCard uses for the company-wide this-month figure —
 * instead of reimplementing profit logic here.
 *
 * See InventorySalesTrendCard's docblock for why `lazy` alone (no tab-gating x-if) is
 * enough here, and why the cache key is scoped per-user.
 */
class InventorySalesByEmployeeCard extends Component
{
    use CachesData;

    public function mount(): void
    {
        Gate::authorize('inventory.master-data.view');
        $this->cacheTtl = 300;
    }

    public function salesByEmployee(): array
    {
        return $this->rememberCache(
            $this->cacheKey('inventory', 'sales-by-employee-card', (string) auth()->id()),
            fn (): array => $this->buildSalesByEmployee(),
        );
    }

    public function placeholder(): string
    {
        return Blade::render(<<<'BLADE'
            <div role="status" aria-label="Loading sales by employee" class="animate-pulse mb-6">
                <div class="rounded-2xl border border-base-200 bg-base-100 p-5">
                    <div class="h-4 w-40 rounded bg-base-300 mb-5"></div>
                    <div class="h-40 rounded-xl bg-base-200"></div>
                </div>
            </div>
            BLADE);
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.inventory-sales-by-employee-card', [
            'salesByEmployee' => $this->salesByEmployee(),
            'scopeLabel'      => CommercialTeamAccess::scopeLabel('sales'),
        ]);
    }

    private function buildSalesByEmployee(): array
    {
        $excluded = [SaleOrderStatus::DRAFT->value, SaleOrderStatus::CANCELLED->value, SaleOrderStatus::RETURNED->value];

        $orders = CommercialTeamAccess::applySalesScope(SaleOrder::query()->where('document_type', 'order'))
            ->whereBetween('ordered_at', [now()->startOfMonth(), now()->endOfDay()])
            ->whereNotIn('status', $excluded)
            ->get(['id', 'sales_executive_id', 'created_by', 'price_tier_code', 'total_amount', 'cogs_amount', 'accounting_invoice_id']);

        return SalesBreakdowns::byEmployee($orders);
    }
}

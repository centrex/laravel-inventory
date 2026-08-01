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
 * Split out of the inventory dashboard's Overview tab — this-month revenue/order-count
 * broken down by price tier, same scope and excluded-status rules as
 * InventorySalesTrendCard so the figures add up to the same total.
 *
 * See InventorySalesTrendCard's docblock for why `lazy` alone (no tab-gating x-if) is
 * enough here, and why the cache key is scoped per-user.
 */
class InventorySalesByPriceTierCard extends Component
{
    use CachesData;

    public function mount(): void
    {
        Gate::authorize('inventory.master-data.view');
        $this->cacheTtl = 300;
    }

    public function salesByPriceTier(): array
    {
        return $this->rememberCache(
            $this->cacheKey('inventory', 'sales-by-price-tier-card', (string) auth()->id()),
            fn (): array => $this->buildSalesByPriceTier(),
        );
    }

    public function placeholder(): string
    {
        return Blade::render(<<<'BLADE'
            <div role="status" aria-label="Loading sales by price tier" class="animate-pulse mb-6">
                <div class="rounded-2xl border border-base-200 bg-base-100 p-5">
                    <div class="h-4 w-40 rounded bg-base-300 mb-5"></div>
                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div class="h-56 rounded-xl bg-base-200"></div>
                        <div class="h-56 rounded-xl bg-base-200"></div>
                    </div>
                </div>
            </div>
            BLADE);
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.inventory-sales-by-price-tier-card', [
            'salesByPriceTier' => $this->salesByPriceTier(),
            'scopeLabel'       => CommercialTeamAccess::scopeLabel('sales'),
        ]);
    }

    private function buildSalesByPriceTier(): array
    {
        $excluded = [SaleOrderStatus::DRAFT->value, SaleOrderStatus::CANCELLED->value, SaleOrderStatus::RETURNED->value];

        $orders = CommercialTeamAccess::applySalesScope(SaleOrder::query()->where('document_type', 'order'))
            ->whereBetween('ordered_at', [now()->startOfMonth(), now()->endOfDay()])
            ->whereNotIn('status', $excluded)
            ->get(['id', 'price_tier_code', 'total_amount', 'cogs_amount', 'accounting_invoice_id']);

        return SalesBreakdowns::byPriceTier($orders);
    }
}

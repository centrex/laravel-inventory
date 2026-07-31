<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Enums\SaleOrderStatus;
use Centrex\Inventory\Models\SaleOrder;
use Centrex\Inventory\Support\CommercialTeamAccess;
use Centrex\TallUi\Concerns\CachesData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\{Blade, Gate};
use Livewire\Component;

/**
 * Split out of the inventory dashboard's Overview tab — draft sale orders are excluded
 * from the Sales Order Trend (they aren't real commitments yet), so this surfaces them
 * separately: count, pending value, and the most recent few, awaiting confirmation.
 *
 * See InventorySalesTrendCard's docblock for why `lazy` alone (no tab-gating x-if) is
 * enough here, and why the cache key is scoped per-user.
 */
class InventoryDraftSaleOrdersCard extends Component
{
    use CachesData;

    public function mount(): void
    {
        Gate::authorize('inventory.master-data.view');
        $this->cacheTtl = 300;
    }

    public function draftSaleOrders(): array
    {
        return $this->rememberCache(
            $this->cacheKey('inventory', 'draft-sale-orders-card', (string) auth()->id()),
            fn (): array => $this->buildDraftSaleOrders(),
        );
    }

    public function placeholder(): string
    {
        return Blade::render(<<<'BLADE'
            <div role="status" aria-label="Loading draft sale orders" class="animate-pulse mb-6">
                <div class="rounded-2xl border border-base-200 bg-base-100 p-5">
                    <div class="h-4 w-40 rounded bg-base-300 mb-5"></div>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 mb-5">
                        <div class="h-20 rounded-xl bg-base-200"></div>
                        <div class="h-20 rounded-xl bg-base-200"></div>
                    </div>
                    <div class="h-32 rounded-xl bg-base-200"></div>
                </div>
            </div>
            BLADE);
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.inventory-draft-sale-orders-card', [
            'draftSaleOrders' => $this->draftSaleOrders(),
        ]);
    }

    private function buildDraftSaleOrders(): array
    {
        $orders = CommercialTeamAccess::applySalesScope(
            SaleOrder::query()->where('document_type', 'order'),
        )
            ->where('status', SaleOrderStatus::DRAFT->value)
            ->with('customer:id,name')
            ->latest('ordered_at')
            ->get(['id', 'so_number', 'customer_id', 'warehouse_id', 'ordered_at', 'total_amount']);

        return [
            'count' => $orders->count(),
            'total' => (float) $orders->sum('total_amount'),
            // Flattened to plain scalars rather than caching the SaleOrder models
            // themselves — the cache store serializes this array with PHP's native
            // serialize(), and a model surviving into that blob comes back as
            // __PHP_Incomplete_Class if its class isn't autoloaded yet on the next
            // request, so a later ->id/->customer access throws deep inside the view.
            'recent' => $orders->take(5)->map(fn (SaleOrder $order): array => [
                'id'            => $order->id,
                'so_number'     => $order->so_number,
                'customer_name' => $order->customer?->name,
                'ordered_at'    => $order->ordered_at?->format('d M Y'),
                'total_amount'  => (float) $order->total_amount,
            ])->all(),
        ];
    }
}

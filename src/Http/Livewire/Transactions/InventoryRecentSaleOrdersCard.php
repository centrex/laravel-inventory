<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Http\Livewire\Transactions\Concerns\ScopesSalesReport;
use Centrex\Inventory\Models\SaleOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\{Blade, Gate};
use Livewire\{Component, WithPagination};

/**
 * Split out of SalesReportPage's "Recent Sales" tab — matching orders (real pagination, not
 * a hard cap) plus the order-detail modal, scoped to the same date/customer/product filters
 * as the Sale Statistics and Sold Products tabs (see ScopesSalesReport). Not cached (unlike
 * its sibling tabs) — both viewingOrderId and the paginator's current page are per-request
 * interactive state, not a report figure, so this component isn't a pure function of its
 * filter props.
 */
class InventoryRecentSaleOrdersCard extends Component
{
    use ScopesSalesReport;
    use WithPagination;

    public ?int $viewingOrderId = null;

    public int $perPage = 15;

    public function mount(string $startDate = '', string $endDate = '', ?int $customerId = null, ?int $productId = null, ?int $employeeId = null): void
    {
        Gate::authorize('inventory.reports.view');

        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->customerId = $customerId;
        $this->productId = $productId;
        $this->employeeId = $employeeId;
    }

    public function viewOrder(int $id): void
    {
        $this->viewingOrderId = $id;
        $this->dispatch('open-modal', 'sale-order-detail');
    }

    public function closeOrderModal(): void
    {
        $this->viewingOrderId = null;
        $this->dispatch('close-modal', 'sale-order-detail');
    }

    public function placeholder(): string
    {
        return Blade::render(<<<'BLADE'
            <div role="status" aria-label="Loading recent sale orders" class="animate-pulse">
                <div class="h-72 rounded-2xl border border-base-200 bg-base-100"></div>
            </div>
            BLADE);
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.inventory-recent-sale-orders-card', [
            'saleOrders'   => $this->recentOrders(),
            'viewingOrder' => $this->viewingOrderId
                ? SaleOrder::query()->with(['customer', 'warehouse', 'items.product', 'items.variant'])->find($this->viewingOrderId)
                : null,
        ]);
    }

    /**
     * Pass the current page explicitly (via HandlesPagination::getPage(), which
     * previousPage()/nextPage()/gotoPage() all funnel through) rather than relying on
     * paginate()'s implicit Paginator::currentPageResolver() — Livewire only registers that
     * resolver while actually booting the component through its own request lifecycle, so a
     * unit test that instantiates this class directly (see SalesReportInvoiceScopingTest's
     * reflection convention, used here too) would otherwise always resolve page 1.
     */
    private function recentOrders(): LengthAwarePaginator
    {
        return $this->scopedSalesQuery()
            ->with(['customer', 'warehouse'])
            ->latest('ordered_at')
            ->latest('id')
            ->paginate($this->perPage, ['*'], 'page', $this->getPage());
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }
}

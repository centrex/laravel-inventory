<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions\Concerns;

use Centrex\Inventory\Models\SaleOrder;
use Centrex\Inventory\Support\CommercialTeamAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Shared date/customer/product scoping for the Sales Report's tab cards
 * (InventorySalesStatisticsCard, InventoryRecentSaleOrdersCard, InventorySoldProductsCard) —
 * each is mounted independently, given the same four filter values by SalesReportPage, so
 * this keeps the underlying SaleOrder query (and its commercial-team visibility scoping)
 * identical across all three instead of letting three copies drift apart.
 */
trait ScopesSalesReport
{
    public string $startDate = '';

    public string $endDate = '';

    public ?int $customerId = null;

    public ?int $productId = null;

    public ?int $employeeId = null;

    protected function scopedSalesQuery(): Builder
    {
        $query = SaleOrder::query()
            ->where('document_type', 'order')
            ->when($this->startDate !== '', fn ($query) => $query->whereDate('ordered_at', '>=', $this->startDate))
            ->when($this->endDate !== '', fn ($query) => $query->whereDate('ordered_at', '<=', $this->endDate))
            ->when($this->customerId, fn ($query) => $query->where('customer_id', $this->customerId))
            ->when($this->productId, fn ($query) => $query->whereHas('items', fn ($itemQuery) => $itemQuery->where('product_id', $this->productId)))
            ->when($this->employeeId, fn ($query) => $query->where('sales_executive_id', $this->employeeId));

        CommercialTeamAccess::applySalesScope($query);

        return $query;
    }

    /** Draft and cancelled orders don't count as "sold" — excluded from every monetary figure. */
    protected function scopedOrderIds(): Collection
    {
        return $this->scopedSalesQuery()
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->pluck('id');
    }
}

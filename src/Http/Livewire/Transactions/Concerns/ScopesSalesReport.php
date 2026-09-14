<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions\Concerns;

use Centrex\Inventory\Models\{SaleOrder, SaleReturn};
use Centrex\Inventory\Support\CommercialTeamAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Shared date/customer/product scoping for the Sales Report's tab cards
 * (InventorySalesStatisticsCard, InventoryRecentSaleOrdersCard, InventorySoldProductsCard,
 * InventoryReturnedProductsCard) — each is mounted independently, given the same four filter
 * values by SalesReportPage, so this keeps the underlying SaleOrder query (and its
 * commercial-team visibility scoping) identical across all of them instead of letting
 * separate copies drift apart.
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

    /**
     * SaleReturn carries neither the owner columns (created_by, sales_owner_id, ...) nor an
     * ordered_at column, so it can't reuse scopedSalesQuery() directly: visibility is scoped
     * by its parent sale order (via a sale_order_id subquery over the same
     * CommercialTeamAccess::applySalesScope() rule scopedSalesQuery() applies), while the
     * date/employee filters apply against the return itself (returned_at, and the parent
     * order's sales_executive_id). Only 'posted' returns have actually been received back
     * into stock — draft returns don't count as "returned" yet.
     */
    protected function scopedSaleReturnsQuery(): Builder
    {
        $visibleOrders = SaleOrder::query();
        CommercialTeamAccess::applySalesScope($visibleOrders);

        return SaleReturn::query()
            ->where('status', 'posted')
            ->whereIn('sale_order_id', $visibleOrders->select('id'))
            ->when($this->startDate !== '', fn ($query) => $query->whereDate('returned_at', '>=', $this->startDate))
            ->when($this->endDate !== '', fn ($query) => $query->whereDate('returned_at', '<=', $this->endDate))
            ->when($this->customerId, fn ($query) => $query->where('customer_id', $this->customerId))
            ->when($this->productId, fn ($query) => $query->whereHas('items', fn ($itemQuery) => $itemQuery->where('product_id', $this->productId)))
            ->when($this->employeeId, fn ($query) => $query->whereHas('saleOrder', fn ($orderQuery) => $orderQuery->where('sales_executive_id', $this->employeeId)));
    }

    protected function scopedReturnIds(): Collection
    {
        return $this->scopedSaleReturnsQuery()->pluck('id');
    }
}

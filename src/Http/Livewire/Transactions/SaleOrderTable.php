<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Models\SaleOrder;
use Centrex\Inventory\Support\{CommercialTeamAccess, StatusBadge};
use Centrex\TallUi\Concerns\WithFilters;
use Centrex\TallUi\DataTable\{Column, Filter};
use Centrex\TallUi\Livewire\DataTable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Backs both the Sale Orders and Quotations index pages — $documentType is
 * passed in from SaleOrderIndexPage via <livewire:inventory-sale-order-table
 * :document-type="$documentType" />, mirroring how the parent page already
 * splits 'order' vs 'quotation'.
 */
class SaleOrderTable extends DataTable
{
    use WithFilters;

    public string $documentType = 'order';

    public string $defaultSortBy = 'ordered_at';

    public string $defaultSortDirection = 'desc';

    public function mount(string $documentType = 'order'): void
    {
        $this->documentType = $documentType === 'quotation' ? 'quotation' : 'order';

        parent::mount();
    }

    public function columns(): array
    {
        $currency = (string) config('inventory.base_currency', 'BDT');

        return [
            Column::make('Number', 'so_number')->searchable()->sortable()
                ->view('inventory::livewire.partials.sale-order-table.number'),
            Column::make('Date', 'ordered_at')->sortable()->format('date'),
            Column::make('Customer', 'customer.name')->relation('customer')->searchable()
                ->view('inventory::livewire.partials.sale-order-table.customer'),
            Column::make('Warehouse', 'warehouse.name')->relation('warehouse')->searchable()->hideOnMobile(),
            Column::make('Status', 'status')->badge('neutral', StatusBadge::colors()),
            Column::make('Total', 'total_local')->currency($currency)->sortable()->summable(),
            Column::make('Due', 'due_amount')->currency($currency)->sortable(),
            Column::make('Actions')
                ->view('inventory::livewire.partials.sale-order-table.actions'),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::select('Status', 'status', [
                'draft'      => 'Draft',
                'confirmed'  => 'Confirmed',
                'processing' => 'Processing',
                'shipped'    => 'Shipped',
                'partial'    => 'Partially Fulfilled',
                'fulfilled'  => 'Fulfilled',
                'completed'  => 'Completed',
                'cancelled'  => 'Cancelled',
                'returned'   => 'Returned',
            ]),
        ];
    }

    public function query(): Builder
    {
        $query = SaleOrder::query()
            ->with(['customer', 'warehouse'])
            ->where('document_type', $this->documentType);

        CommercialTeamAccess::applySalesScope($query);

        return $query;
    }

    protected function applySearchConstraint(Builder $query, string $column, string $search): void
    {
        if ($column === 'customer.name') {
            $query->orWhereHas('customer', function (Builder $q) use ($search): void {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('organization_name', 'like', '%' . $search . '%');
            });

            return;
        }

        parent::applySearchConstraint($query, $column, $search);
    }
}

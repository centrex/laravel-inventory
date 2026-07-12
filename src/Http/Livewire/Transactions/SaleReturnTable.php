<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Models\SaleReturn;
use Centrex\Inventory\Support\StatusBadge;
use Centrex\TallUi\DataTable\Column;
use Centrex\TallUi\Livewire\DataTable;
use Illuminate\Database\Eloquent\Builder;

class SaleReturnTable extends DataTable
{
    public string $defaultSortBy = 'returned_at';

    public string $defaultSortDirection = 'desc';

    public function columns(): array
    {
        return [
            Column::make('Number', 'return_number')->searchable()->sortable()
                ->view('inventory::livewire.partials.sale-return-table.number'),
            Column::make('Customer', 'customer.name')->relation('customer'),
            Column::make('Warehouse', 'warehouse.name')->relation('warehouse'),
            Column::make('Status', 'status')->badge('neutral', StatusBadge::colors()),
            Column::make('Action')
                ->view('inventory::livewire.partials.sale-return-table.actions'),
        ];
    }

    public function query(): Builder
    {
        return SaleReturn::query()->with(['customer', 'warehouse', 'saleOrder']);
    }
}

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
            Column::make('Date', 'returned_at')->sortable()->format('date'),
            Column::make('Customer', 'customer.name')->relation('customer')->searchable()
                ->view('inventory::livewire.partials.sale-return-table.customer'),
            Column::make('Sale Order', 'saleOrder.so_number')->relation('saleOrder')->searchable()
                ->view('inventory::livewire.partials.sale-return-table.sale-order'),
            Column::make('Sale Order Date', 'saleOrder.so_date')->relation('saleOrder')->sortable()
                ->format('date'),
            Column::make('Warehouse', 'warehouse.name')->relation('warehouse'),
            Column::make('Status', 'status')->badge('neutral', StatusBadge::colors()),
            Column::make('Refundable', 'creditMemo.status')->relation('creditMemo')
                ->view('inventory::livewire.partials.sale-return-table.refundable')
                // The cell view renders a status badge + the refundable amount, but
                // CSV/Excel export never renders Blade views — it only reads $key via
                // data_get(), so without this the export silently dropped the refund
                // value and exported the raw credit-memo status enum instead.
                ->exportKey('creditMemo.refundable_amount'),
            Column::make('Action')
                ->view('inventory::livewire.partials.sale-return-table.actions'),
        ];
    }

    public function query(): Builder
    {
        return SaleReturn::query()->with(['customer', 'warehouse', 'saleOrder', 'creditMemo']);
    }
}

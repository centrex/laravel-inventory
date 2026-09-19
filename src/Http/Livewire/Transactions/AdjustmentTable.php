<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Models\Adjustment;
use Centrex\Inventory\Support\StatusBadge;
use Centrex\TallUi\DataTable\Column;
use Centrex\TallUi\Livewire\DataTable;
use Illuminate\Database\Eloquent\Builder;

class AdjustmentTable extends DataTable
{
    public string $defaultSortBy = 'adjusted_at';

    public string $defaultSortDirection = 'desc';

    public function columns(): array
    {
        return [
            Column::make('Number', 'adjustment_number')->searchable()->sortable()
                ->view('inventory::livewire.partials.adjustment-table.number'),
            Column::make('Warehouse', 'warehouse.name')->relation('warehouse'),
            Column::make('Reason', 'reason')->view('inventory::livewire.partials.adjustment-table.reason'),
            Column::make('Status', 'status')->badge('neutral', StatusBadge::colors()),
            Column::make('Adjusted At', 'adjusted_at')->sortable()->format('date'),
            Column::make('Action')
                ->view('inventory::livewire.partials.adjustment-table.actions'),
        ];
    }

    public function query(): Builder
    {
        return Adjustment::query()->with('warehouse');
    }
}

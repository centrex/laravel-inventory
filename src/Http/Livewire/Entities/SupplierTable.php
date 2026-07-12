<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Entities;

use Centrex\Inventory\Models\Supplier;
use Centrex\Inventory\Support\InventoryEntityRegistry;
use Centrex\TallUi\Concerns\WithFilters;
use Centrex\TallUi\DataTable\Column;
use Centrex\TallUi\Livewire\DataTable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

class SupplierTable extends DataTable
{
    use WithFilters;

    private const ENTITY = 'suppliers';

    /** Re-render after the parent page deletes a record. */
    #[On('supplier-table:refresh')]
    public function refreshTable(): void {}

    public function columns(): array
    {
        $definition = InventoryEntityRegistry::definition(self::ENTITY);
        $columns = [
            Column::make('Image', 'entity_image')
                ->view('inventory::livewire.partials.entity-table.image')
                ->excludeFromExport(),
        ];

        foreach ($definition['index_columns'] as $column) {
            $col = Column::make((string) str($column)->replace('_', ' ')->title(), $column)
                ->view('inventory::livewire.partials.entity-table.cell');

            if (InventoryEntityRegistry::relationNameForColumn(self::ENTITY, $column) === null) {
                $col->sortable();
            }

            if (in_array($column, $definition['search'], true)) {
                $col->searchable();
            }

            $columns[] = $col;
        }

        $columns[] = Column::make('Actions')
            ->view('inventory::livewire.partials.supplier-table.actions');

        return $columns;
    }

    public function query(): Builder
    {
        $query = Supplier::query();

        $relations = collect(InventoryEntityRegistry::indexColumns(self::ENTITY))
            ->map(fn (string $column): ?string => InventoryEntityRegistry::relationNameForColumn(self::ENTITY, $column))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($relations !== []) {
            $query->with($relations);
        }

        return $query;
    }

    public function renderHtmlColumn(array $column, mixed $row): string
    {
        $key = $column['key'];

        if ($key === null || $key === 'entity_image') {
            return parent::renderHtmlColumn($column, $row);
        }

        $relation = InventoryEntityRegistry::relationNameForColumn(self::ENTITY, $key);

        if ($relation === null) {
            return parent::renderHtmlColumn($column, $row);
        }

        $value = data_get($row->{$relation}, InventoryEntityRegistry::relatedLabelForColumn(self::ENTITY, $key));

        return view('inventory::livewire.partials.entity-table.cell', ['row' => $row, 'value' => $value])->render();
    }
}

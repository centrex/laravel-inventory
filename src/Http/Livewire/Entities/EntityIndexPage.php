<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Entities;

use Centrex\Inventory\Support\{CommercialTeamAccess, InventoryEntityRegistry};
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\{Component, WithPagination};

#[Layout('layouts.app')]
class EntityIndexPage extends Component
{
    use WithPagination;

    public string $entity = '';

    public string $search = '';

    public function mount(string $entity): void
    {
        InventoryEntityRegistry::definition($entity);

        $this->entity = $entity;
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function delete(int $recordId): void
    {
        $model = InventoryEntityRegistry::makeModel($this->entity);
        $query = $model->newQuery();
        $this->applyEntityScope($query);

        $query->findOrFail($recordId)->delete();

        $this->dispatch('notify', type: 'success', message: 'Record deleted.');
        $this->resetPage();
    }

    public function render(): View
    {
        $definition = InventoryEntityRegistry::definition($this->entity);
        $model = InventoryEntityRegistry::makeModel($this->entity);
        $query = $model->newQuery()->latest($model->getKeyName());
        $this->applyEntityScope($query);
        $fieldDefinitions = collect($definition['form_fields'])
            ->keyBy('name')
            ->all();
        $relations = collect(InventoryEntityRegistry::indexColumns($this->entity))
            ->map(fn (string $column): ?string => $this->relationNameForColumn($column, $fieldDefinitions))
            ->filter()
            ->values()
            ->all();

        if ($relations !== []) {
            $query->with($relations);
        }

        if ($this->search !== '' && $definition['search'] !== []) {
            $search = $this->search;
            $query->where(function ($builder) use ($definition, $search): void {
                foreach ($definition['search'] as $column) {
                    $builder->orWhere($column, 'like', '%' . $search . '%');
                }
            });
        }

        return view('inventory::livewire.entities.index-page', [
            'definition'       => $definition,
            'columns'          => InventoryEntityRegistry::indexColumns($this->entity),
            'fieldDefinitions' => $fieldDefinitions,
            'showImageThumb'   => $this->showsImageThumb(),
            'records'          => $query->paginate(15),
        ]);
    }

    private function relationNameForColumn(string $column, array $fieldDefinitions): ?string
    {
        $field = $fieldDefinitions[$column] ?? null;

        if (!is_array($field) || empty($field['related_model']) || !str_ends_with($column, '_id')) {
            return null;
        }

        $relation = Str::camel((string) Str::beforeLast($column, '_id'));

        return method_exists(InventoryEntityRegistry::makeModel($this->entity), $relation)
            ? $relation
            : null;
    }

    private function applyEntityScope($query): void
    {
        if ($this->entity === 'customers') {
            CommercialTeamAccess::applySalesScope($query);
        }
    }

    private function showsImageThumb(): bool
    {
        return in_array($this->entity, ['products', 'product-categories', 'product-brands', 'customers', 'suppliers'], true);
    }
}

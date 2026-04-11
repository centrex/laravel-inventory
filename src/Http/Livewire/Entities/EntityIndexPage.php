<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Entities;

use Centrex\Inventory\Support\InventoryEntityRegistry;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('inventory::layouts.app')]
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
        $model->newQuery()->findOrFail($recordId)->delete();

        session()->flash('inventory.status', 'Record deleted.');
        $this->resetPage();
    }

    public function render(): View
    {
        $definition = InventoryEntityRegistry::definition($this->entity);
        $model = InventoryEntityRegistry::makeModel($this->entity);
        $query = $model->newQuery()->latest($model->getKeyName());

        if ($this->search !== '' && $definition['search'] !== []) {
            $search = $this->search;
            $query->where(function ($builder) use ($definition, $search): void {
                foreach ($definition['search'] as $column) {
                    $builder->orWhere($column, 'like', '%' . $search . '%');
                }
            });
        }

        return view('inventory::livewire.entities.index-page', [
            'definition' => $definition,
            'columns'    => InventoryEntityRegistry::indexColumns($this->entity),
            'records'    => $query->paginate(15),
        ]);
    }
}

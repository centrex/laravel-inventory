<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Entities;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Support\InventoryEntityRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class EntityFormPage extends Component
{
    public string $entity = '';

    public ?int $recordId = null;

    public array $form = [];

    public function mount(string $entity, ?int $recordId = null): void
    {
        $definition = InventoryEntityRegistry::definition($entity);

        $this->entity = $entity;
        $this->recordId = $recordId;
        $this->form = InventoryEntityRegistry::defaultFormData($entity);

        if ($recordId !== null) {
            $record = $this->record();

            foreach ($definition['form_fields'] as $field) {
                $value = $record->getAttribute($field['name']);
                $this->form[$field['name']] = $field['type'] === 'json' && is_array($value)
                    ? json_encode($value, JSON_PRETTY_PRINT)
                    : $value;
            }
        }
    }

    public function save()
    {
        $record = $this->record(false);
        $payload = InventoryEntityRegistry::fillablePayload($this->entity, $this->form);
        $validated = validator($payload, InventoryEntityRegistry::validationRules($this->entity, $record, $payload))->validate();

        if ($record) {
            $record->fill($validated)->save();
        } else {
            $model = InventoryEntityRegistry::makeModel($this->entity);
            $record = $model->newQuery()->create($validated);
        }

        session()->flash('inventory.status', InventoryEntityRegistry::definition($this->entity)['singular'] . ' saved.');

        return redirect()->route("inventory.entities.{$this->entity}.edit", ['recordId' => $record->getKey()]);
    }

    public function render(): View
    {
        return view('inventory::livewire.entities.form-page', [
            'definition'      => InventoryEntityRegistry::definition($this->entity),
            'options'         => InventoryEntityRegistry::formOptions($this->entity),
            'customerHistory' => $this->entity === 'customers' && $this->recordId
                ? app(Inventory::class)->customerHistory($this->recordId)
                : collect(),
            'customerCreditSnapshot' => $this->entity === 'customers' && $this->recordId
                ? app(Inventory::class)->customerCreditSnapshot($this->recordId)
                : null,
        ]);
    }

    private function record(bool $failIfMissing = true): ?Model
    {
        if ($this->recordId === null) {
            return null;
        }

        $model = InventoryEntityRegistry::makeModel($this->entity);
        $query = $model->newQuery();

        return $failIfMissing
            ? $query->findOrFail($this->recordId)
            : $query->find($this->recordId);
    }
}

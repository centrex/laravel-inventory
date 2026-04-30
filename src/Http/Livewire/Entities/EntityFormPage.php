<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Entities;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Support\{CommercialTeamAccess, InventoryEntityRegistry};
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class EntityFormPage extends Component
{
    use WithFileUploads;

    public string $entity = '';

    public ?int $recordId = null;

    public array $form = [];

    public $primaryImage = null;

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

        if ($this->primaryImage) {
            $this->validate([
                'primaryImage' => ['image', 'max:4096'],
            ]);
        }

        if ($record) {
            $record->fill($validated)->save();
        } else {
            $model = InventoryEntityRegistry::makeModel($this->entity);
            $record = $model->newQuery()->create($validated);
        }

        $this->storePrimaryImage($record);

        $this->dispatch('notify', type: 'success', message: InventoryEntityRegistry::definition($this->entity)['singular'] . ' saved.');

        return redirect()->route("inventory.entities.{$this->entity}.edit", ['recordId' => $record->getKey()]);
    }

    public function removeSelectedImage(): void
    {
        $this->primaryImage = null;
    }

    public function updatedPrimaryImage(): void
    {
        if (!$this->primaryImage) {
            return;
        }

        $this->validate([
            'primaryImage' => ['image', 'max:4096'],
        ]);
    }

    public function uploadPrimaryImage(): void
    {
        if (!$this->supportsPrimaryImage() || !$this->recordId || !$this->primaryImage) {
            return;
        }

        $this->validate([
            'primaryImage' => ['image', 'max:4096'],
        ]);

        $this->storePrimaryImage($this->record());

        $this->dispatch('notify', type: 'success', message: 'Image uploaded.');
    }

    public function deletePrimaryImage(): void
    {
        if (!$this->supportsPrimaryImage() || !$this->recordId) {
            return;
        }

        $record = $this->record();

        if (!method_exists($record, 'primaryImageCollectionName')) {
            return;
        }

        $record->clearMediaCollection($record->primaryImageCollectionName());

        $this->dispatch('notify', type: 'success', message: 'Image removed.');
    }

    public function render(): View
    {
        $record = $this->recordId ? $this->record(false) : null;

        return view('inventory::livewire.entities.form-page', [
            'definition'      => InventoryEntityRegistry::definition($this->entity),
            'options'         => InventoryEntityRegistry::formOptions($this->entity),
            'supportsPrimaryImage' => $this->supportsPrimaryImage(),
            'currentPrimaryImageUrl' => $record?->primary_image_url ?: null,
            'currentPrimaryImageSrcset' => $record?->primary_image_srcset ?: null,
            'customerHistory' => $this->entity === 'customers' && $this->recordId
                ? app(Inventory::class)->customerHistory($this->recordId)
                : collect(),
            'customerCreditSnapshot' => $this->entity === 'customers' && $this->recordId
                ? app(Inventory::class)->customerCreditSnapshot($this->recordId)
                : null,
            'customerAnalytics' => $this->entity === 'customers' && $this->recordId
                ? app(Inventory::class)->customerAnalytics($this->recordId)
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

        if ($this->entity === 'customers') {
            CommercialTeamAccess::applySalesScope($query);
        }

        return $failIfMissing
            ? $query->findOrFail($this->recordId)
            : $query->find($this->recordId);
    }

    private function supportsPrimaryImage(): bool
    {
        return in_array($this->entity, ['products', 'product-categories', 'product-brands', 'customers', 'suppliers'], true);
    }

    private function storePrimaryImage(Model $record): void
    {
        if (!$this->supportsPrimaryImage() || !$this->primaryImage || !method_exists($record, 'primaryImageCollectionName')) {
            return;
        }

        $record
            ->addMedia($this->primaryImage)
            ->toMediaCollection($record->primaryImageCollectionName());

        $this->primaryImage = null;
    }
}

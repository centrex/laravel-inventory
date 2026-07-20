<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Entities;

use Centrex\Inventory\Support\{CommercialTeamAccess, EntityUserProvisioner, InventoryEntityRegistry};
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\{Component, WithFileUploads};

#[Layout('layouts.app')]
class EntityFormPage extends Component
{
    use WithFileUploads;

    public string $entity = '';

    // Not strictly `int` — this nests <livewire:inventory-manage-addresses>, and Livewire's
    // hydration sets properties directly (bypassing mount()'s type coercion); a round-tripped
    // string value would otherwise throw a TypeError.
    public int|string|null $recordId = null;

    public array $form = [];

    public $primaryImage = null;

    public function mount(string $entity, ?int $recordId = null): void
    {
        Gate::authorize('inventory.master-data.manage');

        $definition = InventoryEntityRegistry::definition($entity);

        $this->entity = $entity;
        $this->recordId = $recordId;
        $this->form = InventoryEntityRegistry::defaultFormData($entity);

        if ($recordId !== null) {
            $record = $this->record();

            foreach ($definition['form_fields'] as $field) {
                // Virtual fields (e.g. create_user/user_password) drive login-user
                // provisioning at creation time only — they aren't real columns, so
                // reading them off an existing record trips preventAccessingMissingAttributes.
                // defaultFormData() above already seeded the right default for them.
                if ($field['virtual'] ?? false) {
                    continue;
                }

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
        $payload = InventoryEntityRegistry::fillablePayload($this->entity, $this->form, forCreate: $record === null);
        $validated = validator($payload, InventoryEntityRegistry::validationRules($this->entity, $record, $payload))->validate();

        if ($this->primaryImage) {
            $this->validate([
                'primaryImage' => ['image', 'max:4096'],
            ]);
        }

        $persistable = Arr::except($validated, InventoryEntityRegistry::virtualFieldNames($this->entity));

        if ($record) {
            $record->fill($persistable)->save();
        } else {
            $model = InventoryEntityRegistry::makeModel($this->entity);
            $record = $model->newQuery()->create($persistable);
            EntityUserProvisioner::provision($this->entity, $record, $validated);
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
        $supportsPrimaryImage = $this->supportsPrimaryImage($record);

        return view('inventory::livewire.entities.form-page', [
            'definition'                => InventoryEntityRegistry::definition($this->entity),
            'options'                   => InventoryEntityRegistry::formOptions($this->entity),
            'supportsPrimaryImage'      => $supportsPrimaryImage,
            'currentPrimaryImageUrl'    => $supportsPrimaryImage ? ($record?->primary_image_url ?: null) : null,
            'currentPrimaryImageSrcset' => $supportsPrimaryImage ? ($record?->primary_image_srcset ?: null) : null,
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

    private function supportsPrimaryImage(?Model $record = null): bool
    {
        $model = $record ?? InventoryEntityRegistry::makeModel($this->entity);

        return method_exists($model, 'primaryImageCollectionName')
            && method_exists($model, 'getPrimaryImageUrlAttribute')
            && method_exists($model, 'getPrimaryImageSrcsetAttribute');
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

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Entities;

use Centrex\Inventory\Support\{EntityUserProvisioner, InventoryEntityRegistry};
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class ManageUserAccess extends Component
{
    public string $entity = '';

    // Not strictly `int` — this is a nested component, so Livewire's hydration sets
    // this property directly (bypassing mount()'s type coercion); a round-tripped
    // string value would otherwise throw a TypeError. Cast to int at point of use.
    public int|string $recordId = 0;

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public string $userSearch = '';

    public function mount(string $entity, int $recordId): void
    {
        Gate::authorize('inventory.master-data.manage');

        $this->entity = $entity;
        $this->recordId = $recordId;
    }

    public function openCreateModal(): void
    {
        $this->reset(['newPassword', 'newPasswordConfirmation']);
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'create-login-user');
    }

    public function openLinkModal(): void
    {
        $this->reset(['userSearch']);
        $this->dispatch('open-modal', 'link-existing-user');
    }

    public function createUser(): void
    {
        $record = $this->ownerModel();
        $email = EntityUserProvisioner::resolvedEmail($this->entity, $record);

        if ($email === null) {
            $this->addError('newPassword', 'This record has no email on file — set one on the edit form first.');

            return;
        }

        $this->validate([
            'newPassword'             => ['required', 'string', 'min:8'],
            'newPasswordConfirmation' => ['required', 'same:newPassword'],
        ]);

        EntityUserProvisioner::createAndLink($this->entity, $record, $this->newPassword);

        $this->reset(['newPassword', 'newPasswordConfirmation']);
        $this->dispatch('close-modal', 'create-login-user');
        $this->dispatch('notify', type: 'success', message: 'Login user created and linked.');
    }

    public function linkUser(int $userId): void
    {
        EntityUserProvisioner::linkExisting($this->entity, $this->ownerModel(), $userId);

        $this->reset(['userSearch']);
        $this->dispatch('close-modal', 'link-existing-user');
        $this->dispatch('notify', type: 'success', message: 'User linked.');
    }

    public function unlinkUser(): void
    {
        EntityUserProvisioner::unlink($this->entity, $this->ownerModel());

        $this->dispatch('notify', type: 'success', message: 'User unlinked.');
    }

    public function render(): View
    {
        $record = $this->ownerModel();

        return view('inventory::livewire.entities.manage-user-access', [
            'linkedUser'    => EntityUserProvisioner::linkedUser($this->entity, $record),
            'resolvedEmail' => EntityUserProvisioner::resolvedEmail($this->entity, $record),
            'searchResults' => $this->searchResults(),
        ]);
    }

    /** @return Collection<int, Model> */
    private function searchResults(): Collection
    {
        if (trim($this->userSearch) === '') {
            return collect();
        }

        /** @var class-string<Model> $userModel */
        $userModel = (string) config('auth.providers.users.model', 'App\\Models\\User');

        return $userModel::query()
            ->where(function ($query): void {
                $query->where('name', 'like', '%' . $this->userSearch . '%')
                    ->orWhere('email', 'like', '%' . $this->userSearch . '%');
            })
            ->limit(10)
            ->get(['id', 'name', 'email']);
    }

    private function ownerModel(): Model
    {
        return InventoryEntityRegistry::makeModel($this->entity)->newQuery()->findOrFail((int) $this->recordId);
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Entities;

use Centrex\Inventory\Support\InventoryEntityRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Component;

class ManageAddresses extends Component
{
    public string $entity = '';

    public int $recordId = 0;

    public bool $showModal = false;

    public ?int $editId = null;

    public string $label = '';

    public string $type = 'default';

    public string $street = '';

    public string $street_extra = '';

    public string $city = '';

    public string $state = '';

    public string $district = '';

    public string $post_code = '';

    public string $country_code = '';

    public string $contact_phone = '';

    public string $contact_email = '';

    public bool $is_primary = false;

    public bool $is_billing = false;

    public bool $is_shipping = false;

    public string $notes = '';

    public function mount(string $entity, int $recordId): void
    {
        $this->entity   = $entity;
        $this->recordId = $recordId;
    }

    public function openCreate(): void
    {
        $this->reset([
            'editId', 'label', 'street', 'street_extra', 'city', 'state', 'district',
            'post_code', 'country_code', 'contact_phone', 'contact_email',
            'is_primary', 'is_billing', 'is_shipping', 'notes',
        ]);
        $this->type      = 'default';
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $address = $this->findAddress($id);

        $this->editId        = $id;
        $this->label         = (string) ($address->label ?? '');
        $this->type          = (string) ($address->type ?? 'default');
        $this->street        = (string) ($address->street ?? '');
        $this->street_extra  = (string) ($address->street_extra ?? '');
        $this->city          = (string) ($address->city ?? '');
        $this->state         = (string) ($address->state ?? '');
        $this->district      = (string) ($address->district ?? '');
        $this->post_code     = (string) ($address->post_code ?? '');
        $this->country_code  = (string) ($address->country_code ?? '');
        $this->contact_phone = (string) ($address->contact_phone ?? '');
        $this->contact_email = (string) ($address->contact_email ?? '');
        $this->is_primary    = (bool) ($address->is_primary ?? false);
        $this->is_billing    = (bool) ($address->is_billing ?? false);
        $this->is_shipping   = (bool) ($address->is_shipping ?? false);
        $this->notes         = (string) ($address->notes ?? '');
        $this->showModal     = true;
    }

    public function save(): void
    {
        $this->validate([
            'label'         => ['nullable', 'string', 'max:120'],
            'type'          => ['nullable', 'string', 'max:40'],
            'street'        => ['nullable', 'string', 'max:180'],
            'street_extra'  => ['nullable', 'string', 'max:180'],
            'city'          => ['nullable', 'string', 'max:120'],
            'state'         => ['nullable', 'string', 'max:120'],
            'district'      => ['nullable', 'string', 'max:120'],
            'post_code'     => ['nullable', 'string', 'max:30'],
            'country_code'  => ['nullable', 'string', 'size:2'],
            'contact_phone' => ['nullable', 'string', 'max:60'],
            'contact_email' => ['nullable', 'email', 'max:191'],
            'notes'         => ['nullable', 'string'],
        ]);

        $data = [
            'label'         => $this->label ?: null,
            'type'          => $this->type ?: 'default',
            'street'        => $this->street ?: null,
            'street_extra'  => $this->street_extra ?: null,
            'city'          => $this->city ?: null,
            'state'         => $this->state ?: null,
            'district'      => $this->district ?: null,
            'post_code'     => $this->post_code ?: null,
            'country_code'  => $this->country_code ? strtoupper($this->country_code) : null,
            'contact_phone' => $this->contact_phone ?: null,
            'contact_email' => $this->contact_email ?: null,
            'is_primary'    => $this->is_primary,
            'is_billing'    => $this->is_billing,
            'is_shipping'   => $this->is_shipping,
            'notes'         => $this->notes ?: null,
        ];

        if ($this->editId) {
            $this->findAddress($this->editId)->update($data);
            $this->dispatch('notify', type: 'success', message: 'Address updated.');
        } else {
            $this->ownerModel()->addresses()->create(
                array_merge($data, ['uuid' => (string) Str::uuid()]),
            );
            $this->dispatch('notify', type: 'success', message: 'Address added.');
        }

        $this->showModal = false;
    }

    public function delete(int $id): void
    {
        $this->findAddress($id)->delete();
        $this->dispatch('notify', type: 'success', message: 'Address removed.');
    }

    public function render(): View
    {
        $addresses = $this->ownerModel()->addresses()
            ->orderByDesc('is_primary')
            ->orderByDesc('created_at')
            ->get();

        return view('inventory::livewire.entities.manage-addresses', [
            'addresses' => $addresses,
        ]);
    }

    private function ownerModel(): Model
    {
        return InventoryEntityRegistry::makeModel($this->entity)->newQuery()->findOrFail($this->recordId);
    }

    private function findAddress(int $id): Model
    {
        /** @var class-string<Model> $addressClass */
        $addressClass = config('laravel-addresses.addresses.model', 'Centrex\\Addresses\\Models\\Address');

        return $addressClass::findOrFail($id);
    }
}

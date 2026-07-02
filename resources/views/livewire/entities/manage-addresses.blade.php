<div>
<x-tallui-notification />

<x-tallui-card icon="o-map-pin" :shadow="true">
    <x-slot:title>Addresses</x-slot:title>
    <x-slot:actions>
        <x-tallui-button
            wire:click="openCreate"
            icon="o-plus"
            class="btn-primary btn-sm"
            label="Add Address"
        />
    </x-slot:actions>

    @if ($addresses->isEmpty())
        <x-tallui-empty-state
            title="No addresses yet"
            description="Add a shipping, billing, or contact address."
            icon="o-map-pin"
            size="sm"
        />
    @else
        {{-- Desktop table --}}
        <div class="hidden overflow-x-auto sm:block">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-50 text-xs uppercase text-base-content/50">
                        <th class="pl-4">Label / Type</th>
                        <th>Street</th>
                        <th>City / State</th>
                        <th>Post Code</th>
                        <th>Country</th>
                        <th>Contact</th>
                        <th>Flags</th>
                        <th class="pr-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-base-200">
                    @foreach ($addresses as $address)
                        <tr class="hover:bg-base-50/50">
                            <td class="pl-4 py-2.5">
                                <div class="text-sm font-medium">{{ $address->label ?: '—' }}</div>
                                <div class="text-xs text-base-content/50 capitalize">{{ $address->type ?? 'default' }}</div>
                            </td>
                            <td class="py-2.5 text-sm text-base-content/80">
                                {{ $address->street ?: '—' }}
                                @if ($address->street_extra)
                                    <div class="text-xs text-base-content/50">{{ $address->street_extra }}</div>
                                @endif
                            </td>
                            <td class="py-2.5 text-sm text-base-content/70">
                                {{ implode(', ', array_filter([$address->city, $address->district, $address->state])) ?: '—' }}
                            </td>
                            <td class="py-2.5 text-sm font-mono text-base-content/70">{{ $address->post_code ?: '—' }}</td>
                            <td class="py-2.5 text-sm font-mono text-base-content/70">{{ $address->country_code ?: '—' }}</td>
                            <td class="py-2.5 text-sm text-base-content/60">
                                @if ($address->contact_phone)
                                    <div>{{ $address->contact_phone }}</div>
                                @endif
                                @if ($address->contact_email)
                                    <div class="text-xs">{{ $address->contact_email }}</div>
                                @endif
                                @if (!$address->contact_phone && !$address->contact_email)
                                    —
                                @endif
                            </td>
                            <td class="py-2.5">
                                <div class="flex flex-wrap gap-1">
                                    @if ($address->is_primary)
                                        <x-tallui-badge type="primary" size="xs">Primary</x-tallui-badge>
                                    @endif
                                    @if ($address->is_billing)
                                        <x-tallui-badge type="info" size="xs">Billing</x-tallui-badge>
                                    @endif
                                    @if ($address->is_shipping)
                                        <x-tallui-badge type="success" size="xs">Shipping</x-tallui-badge>
                                    @endif
                                </div>
                            </td>
                            <td class="pr-4 py-2.5 text-right">
                                <div class="flex justify-end gap-1">
                                    <x-tallui-button
                                        wire:click="openEdit({{ $address->id }})"
                                        icon="o-pencil"
                                        class="btn-ghost btn-xs"
                                        title="Edit"
                                    />
                                    <x-tallui-button
                                        wire:click="delete({{ $address->id }})"
                                        wire:confirm="Remove this address?"
                                        icon="o-trash"
                                        class="btn-ghost btn-xs text-error"
                                        title="Delete"
                                    />
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Mobile card stack --}}
        <div class="space-y-3 sm:hidden">
            @foreach ($addresses as $address)
                <div class="rounded-xl border border-base-200 bg-base-50/50 p-3 text-sm">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="font-medium">{{ $address->label ?: ucfirst($address->type ?? 'Address') }}</div>
                            @if ($address->street)
                                <div class="mt-0.5 text-xs text-base-content/60">
                                    {{ implode(', ', array_filter([$address->street, $address->street_extra, $address->city, $address->state, $address->post_code, $address->country_code])) }}
                                </div>
                            @endif
                        </div>
                        <div class="flex gap-1 shrink-0">
                            <x-tallui-button wire:click="openEdit({{ $address->id }})" icon="o-pencil" class="btn-ghost btn-xs" />
                            <x-tallui-button wire:click="delete({{ $address->id }})" wire:confirm="Remove this address?" icon="o-trash" class="btn-ghost btn-xs text-error" />
                        </div>
                    </div>
                    @if ($address->is_primary || $address->is_billing || $address->is_shipping)
                        <div class="mt-2 flex flex-wrap gap-1">
                            @if ($address->is_primary)
                                <x-tallui-badge type="primary" size="xs">Primary</x-tallui-badge>
                            @endif
                            @if ($address->is_billing)
                                <x-tallui-badge type="info" size="xs">Billing</x-tallui-badge>
                            @endif
                            @if ($address->is_shipping)
                                <x-tallui-badge type="success" size="xs">Shipping</x-tallui-badge>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</x-tallui-card>

{{-- Add / Edit Address Modal --}}
<x-tallui-modal id="address-modal" :title="$editId ? 'Edit Address' : 'Add Address'" icon="o-map-pin" size="xl">
    <x-slot:trigger>
        <span
            x-effect="if ($wire.showModal) $dispatch('open-modal', 'address-modal'); else $dispatch('close-modal', 'address-modal')"
            @modal-closed.window="if ($event.detail === 'address-modal') $wire.showModal = false"
        ></span>
    </x-slot:trigger>

    <form wire:submit.prevent="save" class="space-y-4">

        {{-- Label + Type --}}
        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Label" helper="e.g. Office, Warehouse, Home">
                <x-tallui-input wire:model="label" placeholder="Label…" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Type">
                <x-tallui-select wire:model="type">
                    <option value="default">Default</option>
                    <option value="billing">Billing</option>
                    <option value="shipping">Shipping</option>
                    <option value="return">Return</option>
                    <option value="other">Other</option>
                </x-tallui-select>
            </x-tallui-form-group>
        </div>

        {{-- Street --}}
        <x-tallui-form-group label="Street / House / Road" :error="$errors->first('street')">
            <x-tallui-input wire:model="street" placeholder="House #, Road, Area…" />
        </x-tallui-form-group>

        <x-tallui-form-group label="Apartment / Suite / Floor" :error="$errors->first('street_extra')">
            <x-tallui-input wire:model="street_extra" placeholder="Apt, Floor, Suite…" />
        </x-tallui-form-group>

        {{-- City / District / State --}}
        <div class="grid grid-cols-2 gap-4 md:grid-cols-3">
            <x-tallui-form-group label="City" :error="$errors->first('city')">
                <x-tallui-input wire:model="city" placeholder="City…" />
            </x-tallui-form-group>
            <x-tallui-form-group label="District" :error="$errors->first('district')">
                <x-tallui-input wire:model="district" placeholder="District…" />
            </x-tallui-form-group>
            <x-tallui-form-group label="State / Division" :error="$errors->first('state')">
                <x-tallui-input wire:model="state" placeholder="State…" />
            </x-tallui-form-group>
        </div>

        {{-- Post code / Country --}}
        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Post Code" :error="$errors->first('post_code')">
                <x-tallui-input wire:model="post_code" placeholder="Postal code…" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Country Code (2-letter)" :error="$errors->first('country_code')">
                <x-tallui-input wire:model="country_code" placeholder="BD, IN, US…" maxlength="2" class="uppercase" />
            </x-tallui-form-group>
        </div>

        {{-- Contact --}}
        <div class="grid grid-cols-2 gap-4">
            <x-tallui-form-group label="Contact Phone" :error="$errors->first('contact_phone')">
                <x-tallui-input wire:model="contact_phone" type="tel" placeholder="+880…" />
            </x-tallui-form-group>
            <x-tallui-form-group label="Contact Email" :error="$errors->first('contact_email')">
                <x-tallui-input wire:model="contact_email" type="email" placeholder="contact@…" />
            </x-tallui-form-group>
        </div>

        {{-- Notes --}}
        <x-tallui-form-group label="Notes">
            <x-tallui-textarea wire:model="notes" rows="2" placeholder="Delivery instructions, gate code…" />
        </x-tallui-form-group>

        {{-- Flags --}}
        <div class="flex flex-wrap gap-6 rounded-xl border border-base-200 bg-base-50 px-4 py-3">
            <x-tallui-checkbox name="is_primary" label="Primary address" wire:model="is_primary" />
            <x-tallui-checkbox name="is_billing" label="Billing address" wire:model="is_billing" />
            <x-tallui-checkbox name="is_shipping" label="Shipping address" wire:model="is_shipping" />
        </div>

    </form>

    <x-slot:footer>
        <x-tallui-button wire:click="$set('showModal', false)" class="btn-ghost">Cancel</x-tallui-button>
        <x-tallui-button wire:click="save" spinner="save" class="btn-primary">
            {{ $editId ? 'Update Address' : 'Add Address' }}
        </x-tallui-button>
    </x-slot:footer>
</x-tallui-modal>
</div>

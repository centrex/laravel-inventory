<div>
<x-tallui-notification />

<x-tallui-page-header
    :title="($recordId ? 'Edit ' : 'New ') . $definition['singular']"
    :subtitle="'Maintain ' . strtolower($definition['singular']) . ' details.'"
    icon="o-pencil-square"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => $definition['label'], 'href' => route('inventory.entities.' . $entity . '.index')],
            ['label' => $recordId ? 'Edit' : 'New'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-button
            :label="'Back to ' . $definition['label']"
            icon="o-arrow-left"
            :link="route('inventory.entities.' . $entity . '.index')"
            class="btn-ghost btn-sm"
        />
    </x-slot:actions>
</x-tallui-page-header>

<x-tallui-card
    :title="$definition['singular']"
    subtitle="Fill in the fields and save."
    icon="o-document-text"
    :shadow="true"
>
    <form wire:submit="save" class="space-y-5">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach ($definition['form_fields'] as $field)
                <div class="{{ in_array($field['type'], ['textarea', 'json'], true) ? 'md:col-span-2' : '' }}">
                    @if ($field['type'] === 'textarea')
                        <x-tallui-form-group :label="$field['label']" :error="$errors->first('form.' . $field['name'])">
                            <x-tallui-textarea
                                :name="$field['name']"
                                wire:model="form.{{ $field['name'] }}"
                                rows="3"
                            />
                        </x-tallui-form-group>

                    @elseif ($field['type'] === 'json')
                        <x-tallui-form-group :label="$field['label']" :error="$errors->first('form.' . $field['name'])">
                            <x-tallui-textarea
                                :name="$field['name']"
                                placeholder='{"key": "value"}'
                                wire:model="form.{{ $field['name'] }}"
                                rows="3"
                                class="font-mono text-sm"
                            />
                        </x-tallui-form-group>

                    @elseif ($field['type'] === 'select')
                        <x-tallui-form-group :label="$field['label']" :error="$errors->first('form.' . $field['name'])">
                            <x-tallui-select :name="$field['name']" wire:model="form.{{ $field['name'] }}">
                                <option value="">Select {{ strtolower($field['label']) }}…</option>
                                @foreach ($options[$field['name']] ?? [] as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </x-tallui-select>
                        </x-tallui-form-group>

                    @elseif ($field['type'] === 'checkbox')
                        <div class="flex items-center gap-3 pt-6">
                            <x-tallui-checkbox
                                :name="$field['name']"
                                :label="$field['label']"
                                wire:model="form.{{ $field['name'] }}"
                            />
                        </div>

                    @else
                        <x-tallui-form-group :label="$field['label']" :error="$errors->first('form.' . $field['name'])">
                            <x-tallui-input
                                :name="$field['name']"
                                :type="match($field['type']) {
                                    'number' => 'number',
                                    'date'   => 'date',
                                    'email'  => 'email',
                                    default  => 'text',
                                }"
                                :step="$field['type'] === 'number' ? '0.0001' : null"
                                wire:model="form.{{ $field['name'] }}"
                                :class="$errors->has('form.' . $field['name']) ? 'input-error' : ''"
                            />
                        </x-tallui-form-group>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="flex items-center justify-end gap-2 pt-2 border-t border-base-200">
            <x-tallui-button
                :label="'Back to ' . $definition['label']"
                icon="o-arrow-left"
                :link="route('inventory.entities.' . $entity . '.index')"
                class="btn-ghost"
            />
            <x-tallui-button
                :label="'Save ' . $definition['singular']"
                icon="o-check"
                class="btn-primary"
                type="submit"
                :spinner="'save'"
            />
        </div>
    </form>
</x-tallui-card>
</div>

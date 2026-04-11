<div class="grid">
    <x-tallui-page-header :title="($recordId ? 'Edit ' : 'Create ') . $definition['singular']" :subtitle="'Maintain ' . strtolower($definition['singular']) . ' records from the package UI.'" icon="o-pencil-square">
        <x-slot:actions>
            <x-tallui-button :label="'Back to ' . $definition['label']" icon="o-arrow-left" :link="route("inventory.entities.{$entity}.index")" class="btn-ghost btn-sm" />
        </x-slot:actions>
    </x-tallui-page-header>

    <x-tallui-card :title="$definition['singular'] . ' Form'" subtitle="Edit fields and save the record." icon="o-document-text" :shadow="true">
        <form wire:submit="save" class="stack">
            <div class="form-grid">
                @foreach ($definition['form_fields'] as $field)
                    <div class="{{ in_array($field['type'], ['textarea', 'json'], true) ? 'span-2' : '' }}">
                        @if ($field['type'] === 'textarea')
                            <x-tallui-textarea :name="$field['name']" :label="$field['label']" wire:model="form.{{ $field['name'] }}" />
                        @elseif ($field['type'] === 'json')
                            <x-tallui-textarea :name="$field['name']" :label="$field['label']" placeholder='{"key":"value"}' wire:model="form.{{ $field['name'] }}" />
                        @elseif ($field['type'] === 'select')
                            <x-tallui-select :name="$field['name']" :label="$field['label']" placeholder="Select {{ strtolower($field['label']) }}" wire:model="form.{{ $field['name'] }}">
                                @foreach ($options[$field['name']] ?? [] as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </x-tallui-select>
                        @elseif ($field['type'] === 'checkbox')
                            <x-tallui-checkbox :name="$field['name']" :label="$field['label']" wire:model="form.{{ $field['name'] }}" />
                        @else
                            <x-tallui-input
                                :name="$field['name']"
                                :label="$field['label']"
                                type="{{ $field['type'] === 'number' ? 'number' : ($field['type'] === 'date' ? 'date' : ($field['type'] === 'email' ? 'email' : 'text')) }}"
                                step="{{ $field['type'] === 'number' ? '0.0001' : null }}"
                                wire:model="form.{{ $field['name'] }}"
                            />
                        @endif

                        @error("form.{$field['name']}") <div class="danger">{{ $message }}</div> @enderror
                    </div>
                @endforeach
            </div>

            <div class="actions">
                <x-tallui-button :label="'Save ' . $definition['singular']" icon="o-check" class="btn-primary" type="submit" />
            </div>
        </form>
    </x-tallui-card>
</div>

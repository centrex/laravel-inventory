<div class="grid">
    <x-tallui-page-header :title="$definition['label']" :subtitle="'Browse and manage ' . strtolower($definition['label']) . '.'" icon="o-table-cells">
        <x-slot:actions>
            <div style="min-width: 260px;">
                <x-tallui-input name="search" label="" placeholder="Search {{ strtolower($definition['label']) }}" wire:model.live.debounce.300ms="search" />
            </div>
            <x-tallui-button :label="'Create ' . $definition['singular']" icon="o-plus" :link="route("inventory.entities.{$entity}.create")" class="btn-primary btn-sm" />
        </x-slot:actions>
    </x-tallui-page-header>

    <x-tallui-card :title="$definition['label']" subtitle="Listing" icon="o-list-bullet" :shadow="true">
        <div class="table-shell">
            <table>
                <thead>
                    <tr>
                        @foreach ($columns as $column)
                            <th>{{ str($column)->replace('_', ' ')->title() }}</th>
                        @endforeach
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($records as $record)
                        <tr>
                            @foreach ($columns as $column)
                                <td>{{ is_array($record->{$column}) ? json_encode($record->{$column}) : $record->{$column} }}</td>
                            @endforeach
                            <td>
                                <div class="actions">
                                    <x-tallui-button label="Edit" icon="o-pencil-square" :link="route("inventory.entities.{$entity}.edit", ['recordId' => $record->getKey()])" class="btn-ghost btn-sm" />
                                    <x-tallui-button label="Delete" icon="o-trash" class="btn-error btn-sm" type="button" wire:click="delete({{ $record->getKey() }})" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($columns) + 1 }}" class="muted">
                                <x-tallui-badge color="outline">No records found</x-tallui-badge>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="margin-top: 16px;">
            <x-tallui-pagination :paginator="$records" />
        </div>
    </x-tallui-card>
</div>

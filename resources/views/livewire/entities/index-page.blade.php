<div>
<x-tallui-notification />

<x-tallui-page-header
    :title="$definition['label']"
    :subtitle="'Browse and manage ' . strtolower($definition['label']) . '.'"
    icon="o-table-cells"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => $definition['label']],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <div class="w-64">
            <x-tallui-input
                placeholder="Search {{ strtolower($definition['label']) }}…"
                wire:model.live.debounce.300ms="search"
                class="input-sm"
            />
        </div>
        <x-tallui-button
            :label="'New ' . $definition['singular']"
            icon="o-plus"
            :link="route('inventory.entities.' . $entity . '.create')"
            class="btn-primary btn-sm"
        />
    </x-slot:actions>
</x-tallui-page-header>

<x-tallui-card padding="none" :shadow="true">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                    @foreach ($columns as $column)
                        <th class="pl-4 first:pl-5">
                            {{ str($column)->replace('_', ' ')->title() }}
                        </th>
                    @endforeach
                    <th class="pr-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse ($records as $record)
                    <tr class="hover:bg-base-50">
                        @foreach ($columns as $column)
                            <td class="pl-4 first:pl-5 text-sm">
                                @php $val = $record->{$column}; @endphp
                                @if (is_bool($val))
                                    <x-tallui-badge :type="$val ? 'success' : 'neutral'">
                                        {{ $val ? 'Yes' : 'No' }}
                                    </x-tallui-badge>
                                @elseif (is_array($val))
                                    <span class="font-mono text-xs text-base-content/60">{{ json_encode($val) }}</span>
                                @elseif (strlen((string) $val) > 60)
                                    <span title="{{ $val }}">{{ substr($val, 0, 57) }}…</span>
                                @else
                                    {{ $val ?? '—' }}
                                @endif
                            </td>
                        @endforeach
                        <td class="pr-5">
                            <div class="flex justify-end gap-1">
                                @if ($entity === 'customers')
                                    <x-tallui-button
                                        icon="o-clock"
                                        :link="route('inventory.entities.customers.edit', ['recordId' => $record->getKey()]) . '#history'"
                                        class="btn-ghost btn-xs"
                                        :responsive="true"
                                        label="History"
                                    />
                                @endif
                                <x-tallui-button
                                    icon="o-pencil-square"
                                    :link="route('inventory.entities.' . $entity . '.edit', ['recordId' => $record->getKey()])"
                                    class="btn-ghost btn-xs"
                                    :responsive="true"
                                    label="Edit"
                                />
                                <x-tallui-button
                                    icon="o-trash"
                                    class="btn-ghost btn-xs text-error"
                                    type="button"
                                    wire:click="delete({{ $record->getKey() }})"
                                    wire:confirm="Delete this {{ strtolower($definition['singular']) }}?"
                                    :responsive="true"
                                    label="Delete"
                                />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($columns) + 1 }}" class="py-8">
                            <x-tallui-empty-state
                                :title="'No ' . strtolower($definition['label']) . ' yet'"
                                :description="'Create your first ' . strtolower($definition['singular']) . ' to get started.'"
                                icon="o-folder-open"
                                size="sm"
                            >
                                <x-tallui-button
                                    :label="'New ' . $definition['singular']"
                                    icon="o-plus"
                                    :link="route('inventory.entities.' . $entity . '.create')"
                                    class="btn-primary btn-sm"
                                />
                            </x-tallui-empty-state>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($records->hasPages())
        <div class="px-5 py-3 border-t border-base-200">
            {{ $records->links() }}
        </div>
    @endif
</x-tallui-card>
</div>

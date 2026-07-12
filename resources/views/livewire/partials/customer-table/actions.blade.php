@php $auditable = is_subclass_of($row::class, \OwenIt\Auditing\Contracts\Auditable::class); @endphp
<div class="flex justify-end gap-1">
    <x-tallui-button
        icon="o-chart-bar"
        :link="route('inventory.entities.customers.show', ['recordId' => $row->getKey()])"
        class="btn-ghost btn-xs"
        :responsive="true"
        label="Profile"
        wire:navigate
    />
    @can('inventory.customers.audit')
    @if ($auditable)
        <x-tallui-button
            icon="o-clock"
            wire:click="$dispatch('customer-table:audit', { id: {{ $row->getKey() }} })"
            class="btn-ghost btn-xs"
            label="Audit"
            :responsive="true"
        />
    @endif
    @endcan
    @can('inventory.customers.manage')
    <x-tallui-button
        icon="o-pencil-square"
        :link="route('inventory.entities.customers.edit', ['recordId' => $row->getKey()])"
        class="btn-ghost btn-xs"
        label="Edit"
        :responsive="true"
    /> 
    <x-tallui-button
        icon="o-trash"
        class="btn-ghost btn-xs text-error"
        type="button"
        wire:click="$dispatch('customer-table:delete', { id: {{ $row->getKey() }} })"
        wire:confirm="Delete this customer?"
        label="Delete"
        :responsive="true"
    />
    @endcan
   
</div>

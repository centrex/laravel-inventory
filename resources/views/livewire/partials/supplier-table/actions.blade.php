@php $auditable = is_subclass_of($row::class, \OwenIt\Auditing\Contracts\Auditable::class); @endphp
<div class="flex justify-end gap-1">
    @if ($auditable)
        <x-tallui-button
            icon="o-clock"
            wire:click="$dispatch('supplier-table:audit', { id: {{ $row->getKey() }} })"
            class="btn-ghost btn-xs"
            label="Audit"
            :responsive="true"
        />
    @endif
    <x-tallui-button
        icon="o-pencil-square"
        :link="route('inventory.entities.suppliers.edit', ['recordId' => $row->getKey()])"
        class="btn-ghost btn-xs"
        label="Edit"
        :responsive="true"
    />
    <x-tallui-button
        icon="o-trash"
        class="btn-ghost btn-xs text-error"
        type="button"
        wire:click="$dispatch('supplier-table:delete', { id: {{ $row->getKey() }} })"
        wire:confirm="Delete this supplier?"
        label="Delete"
        :responsive="true"
    />
</div>

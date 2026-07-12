@php $auditable = is_subclass_of($row::class, \OwenIt\Auditing\Contracts\Auditable::class); @endphp
<div class="flex justify-end gap-1">
    @can('inventory.products.audit')
    @if ($auditable)
        <x-tallui-button
            icon="o-clock"
            wire:click="$dispatch('product-table:audit', { id: {{ $row->getKey() }} })"
            class="btn-ghost btn-xs"
            label="Audit"
            :responsive="true"
        />
    @endif
    @endcan
    @can('inventory.products.manage')
    <x-tallui-button
        icon="o-pencil-square"
        :link="route('inventory.entities.products.edit', ['recordId' => $row->getKey()])"
        class="btn-ghost btn-xs"
        label="Edit"
        :responsive="true"
    />
    <x-tallui-button
        icon="o-trash"
        class="btn-ghost btn-xs text-error"
        type="button"
        wire:click="$dispatch('product-table:delete', { id: {{ $row->getKey() }} })"
        wire:confirm="Delete this product?"
        label="Delete"
        :responsive="true"
    />
    @endcan
</div>

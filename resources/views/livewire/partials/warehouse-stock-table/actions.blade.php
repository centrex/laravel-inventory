<div class="flex justify-end gap-1">
    @can('inventory.stock-data.audit')
    <x-tallui-button icon="o-clock" wire:click="$dispatch('warehouse-stock-table:audit', { id: {{ $row->getKey() }} })" class="btn-ghost btn-xs" label="Audit" :responsive="true" />
    @endcan

    @can('inventory.stock-data.manage')
    <x-tallui-button icon="o-pencil-square" :link="route('inventory.entities.warehouse-products.edit', ['recordId' => $row->getKey()])" class="btn-ghost btn-xs" label="Edit" :responsive="true" wire:navigate />
    <x-tallui-button
        icon="o-trash"
        class="btn-ghost btn-xs text-error"
        type="button"
        wire:click="$dispatch('warehouse-stock-table:delete', { id: {{ $row->getKey() }} })"
        wire:confirm="Delete this warehouse stock record?"
        label="Delete"
        :responsive="true"
    />
    @endcan
</div>

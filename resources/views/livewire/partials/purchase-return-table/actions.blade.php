<div class="flex justify-end gap-1">
    <x-tallui-button icon="o-eye" :link="route('inventory.purchase-returns.show', ['recordId' => $row->getKey()])" class="btn-ghost btn-xs" label="Open" :responsive="true" wire:navigate />
    <x-tallui-button icon="o-clock" wire:click="$dispatch('purchase-return-table:audit', { id: {{ $row->getKey() }} })" class="btn-ghost btn-xs" label="Audit" :responsive="true" />
</div>

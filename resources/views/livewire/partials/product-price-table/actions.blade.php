<div class="flex justify-end">
    <x-tallui-button
        icon="o-pencil-square"
        label="Edit Prices"
        :link="route('inventory.entities.product-prices.edit', ['recordId' => $row->product_id, 'warehouseId' => $row->warehouse_id])"
        class="btn-ghost btn-xs"
        :responsive="true"
        wire:navigate
    />
</div>

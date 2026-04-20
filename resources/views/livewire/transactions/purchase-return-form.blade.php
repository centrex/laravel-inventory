<div>
<x-tallui-page-header title="New Purchase Return" subtitle="Post stock being returned to a supplier." icon="o-arrow-uturn-right">
    <x-slot:breadcrumbs><x-tallui-breadcrumb :links="[['label' => 'Inventory', 'href' => route('inventory.dashboard')], ['label' => 'Purchase Returns', 'href' => route('inventory.purchase-returns.index')], ['label' => 'New Purchase Return']]" /></x-slot:breadcrumbs>
</x-tallui-page-header>
<form wire:submit="save" class="space-y-4">
    <x-tallui-card title="Return Details" subtitle="Supplier and warehouse context." icon="o-document-text" :shadow="true">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <x-tallui-form-group label="Purchase Order"><x-tallui-select wire:model="purchase_order_id"><option value="">Optional</option>@foreach($purchaseOrders as $order)<option value="{{ $order->id }}">{{ $order->po_number }}</option>@endforeach</x-tallui-select></x-tallui-form-group>
            <x-tallui-form-group label="Warehouse *"><x-tallui-select wire:model="warehouse_id"><option value="">Select</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</x-tallui-select></x-tallui-form-group>
            <x-tallui-form-group label="Supplier *"><x-tallui-select wire:model="supplier_id"><option value="">Select</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</x-tallui-select></x-tallui-form-group>
            <x-tallui-form-group label="Returned At"><x-tallui-input type="date" wire:model="returned_at" /></x-tallui-form-group>
        </div>
    </x-tallui-card>
    <x-tallui-card padding="none" :shadow="true">
        <x-slot:actions><x-tallui-button label="Add Line" icon="o-plus" class="btn-ghost btn-sm" type="button" wire:click="addItem" /></x-slot:actions>
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead><tr class="bg-base-50 text-xs uppercase"><th class="pl-5">Product</th><th>Qty</th><th>Unit Cost</th><th>Notes</th><th class="pr-5"></th></tr></thead>
                <tbody>
                    @foreach($items as $index => $item)
                        <tr>
                            <td class="pl-5"><x-tallui-select wire:model="items.{{ $index }}.product_id"><option value="">Select</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</x-tallui-select></td>
                            <td><x-tallui-input type="number" step="0.0001" wire:model="items.{{ $index }}.qty_returned" /></td>
                            <td><x-tallui-input type="number" step="0.0001" wire:model="items.{{ $index }}.unit_cost_amount" /></td>
                            <td><x-tallui-input wire:model="items.{{ $index }}.notes" /></td>
                            <td class="pr-5 text-right"><x-tallui-button icon="o-trash" class="btn-ghost btn-xs text-error" type="button" wire:click="removeItem({{ $index }})" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-tallui-card>
    <div class="flex justify-end gap-2"><x-tallui-button label="Back" :link="route('inventory.purchase-returns.index')" class="btn-ghost" /><x-tallui-button label="Post Purchase Return" icon="o-check" class="btn-primary" type="submit" :spinner="'save'" /></div>
</form>
</div>

<div>
<x-tallui-page-header title="New Sale Return" subtitle="Post returned customer goods back into stock." icon="o-arrow-uturn-left">
    <x-slot:breadcrumbs><x-tallui-breadcrumb :links="[['label' => 'Inventory', 'href' => route('inventory.dashboard')], ['label' => 'Sale Returns', 'href' => route('inventory.sale-returns.index')], ['label' => 'New Sale Return']]" /></x-slot:breadcrumbs>
</x-tallui-page-header>
<form wire:submit="save" class="space-y-4">
    <x-tallui-card title="Return Details" subtitle="Source document and return destination." icon="o-document-text" :shadow="true">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <x-tallui-form-group label="Sale Order"><x-tallui-select wire:model.live="sale_order_id"><option value="">Optional</option>@foreach($saleOrders as $order)<option value="{{ $order->id }}">{{ $order->so_number }}{{ $order->customer?->organization_name ? ' - ' . $order->customer->organization_name : ' - ' . $order->customer?->name }}</option>@endforeach</x-tallui-select></x-tallui-form-group>
            <x-tallui-form-group label="Warehouse *"><x-tallui-select wire:model="warehouse_id"><option value="">Select</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</x-tallui-select></x-tallui-form-group>
            @if($selectedOrder)
                <x-tallui-form-group label="Customer">
                    <div class="input input-bordered flex items-center bg-base-200/60 text-base-content/80">
                        {{ $selectedOrder->customer?->organization_name ?? $selectedOrder->customer?->name ?? 'Walk-in customer' }}
                    </div>
                </x-tallui-form-group>
            @else
                <x-tallui-form-group label="Customer"><x-tallui-select wire:model="customer_id"><option value="">Optional</option>@foreach($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->name }}</option>@endforeach</x-tallui-select></x-tallui-form-group>
            @endif
            <x-tallui-form-group label="Returned At"><x-tallui-input type="date" wire:model="returned_at" /></x-tallui-form-group>
        </div>
    </x-tallui-card>
    <x-tallui-card padding="none" :shadow="true">
        <x-slot:actions><x-tallui-button label="Add Line" icon="o-plus" class="btn-ghost btn-sm" type="button" wire:click="addItem" /></x-slot:actions>
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead><tr class="bg-base-50 text-xs uppercase"><th class="pl-5">Product</th><th>Available</th><th>Qty</th><th>Unit Price</th><th>Unit Cost</th><th>Notes</th><th class="pr-5"></th></tr></thead>
                <tbody>
                    @foreach($items as $index => $item)
                        <tr>
                            <td class="pl-5">
                                @if($selectedOrder)
                                    <x-tallui-select wire:model.live="items.{{ $index }}.sale_order_item_id">
                                        <option value="">Select</option>
                                        @foreach($products as $product)
                                            <option value="{{ is_array($product) ? $product['id'] : $product->id }}">
                                                {{ is_array($product) ? $product['name'] : $product->name }}
                                            </option>
                                        @endforeach
                                    </x-tallui-select>
                                @else
                                    <x-tallui-select wire:model.live="items.{{ $index }}.product_id">
                                        <option value="">Select</option>                                        
                                        @foreach($products as $product)
                                            <option value="{{ is_array($product) ? $product['id'] : $product->id }}">{{ is_array($product) ? $product['name'] : $product->name }}</option>
                                        @endforeach
                                    </x-tallui-select>
                                @endif
                            </td>
                            <td class="text-sm text-base-content/60">{{ data_get($availableProducts, ($selectedOrder ? ($item['sale_order_item_id'] ?? 0) : ($item['product_id'] ?? 0)) . '.max_qty', '—') }}</td>
                            <td>
                                <x-tallui-input
                                    type="number"
                                    step="0.0001"
                                    :max="data_get($availableProducts, ($selectedOrder ? ($item['sale_order_item_id'] ?? 0) : ($item['product_id'] ?? 0)) . '.max_qty')"
                                    wire:model.live="items.{{ $index }}.qty_returned"
                                />
                                <input type="hidden" wire:model="items.{{ $index }}.product_id" />
                                <input type="hidden" wire:model="items.{{ $index }}.variant_id" />
                            </td>
                            <td><x-tallui-input type="number" step="0.0001" wire:model="items.{{ $index }}.unit_price_amount" /></td>
                            <td><x-tallui-input type="number" step="0.0001" wire:model="items.{{ $index }}.unit_cost_amount" /></td>
                            <td><x-tallui-input wire:model="items.{{ $index }}.notes" /></td>
                            <td class="pr-5 text-right"><x-tallui-button icon="o-trash" class="btn-ghost btn-xs text-error" type="button" wire:click="removeItem({{ $index }})" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-tallui-card>
    <div class="flex justify-end gap-2"><x-tallui-button label="Back" :link="route('inventory.sale-returns.index')" class="btn-ghost" /><x-tallui-button label="Post Sale Return" icon="o-check" class="btn-primary" type="submit" :spinner="'save'" /></div>
</form>
</div>

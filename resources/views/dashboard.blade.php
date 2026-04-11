<x-layouts::app>
<div class="grid">
    <x-tallui-page-header title="Inventory Operations" subtitle="Manage stock, pricing, vendors, customers, and warehouse workflows from the package UI." icon="o-building-storefront">
        <x-slot:actions>
            <x-tallui-button label="New Purchase" icon="o-arrow-down-tray" :link="route('inventory.purchase-orders.create')" class="btn-primary btn-sm" />
            <x-tallui-button label="New Sale" icon="o-shopping-cart" :link="route('inventory.sale-orders.create')" class="btn-primary btn-sm" />
            <x-tallui-button label="POS Terminal" icon="o-device-phone-mobile" :link="route('inventory.pos.index')" class="btn-primary btn-sm" />
            <x-tallui-button label="New Transfer" icon="o-arrows-right-left" :link="route('inventory.transfers.create')" class="btn-primary btn-sm" />
            <x-tallui-button label="New Adjustment" icon="o-scale" :link="route('inventory.adjustments.create')" class="btn-primary btn-sm" />
        </x-slot:actions>
    </x-tallui-page-header>

    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
        <x-tallui-stat title="Master Modules" value="{{ count($entities) }}" desc="Configured entity screens" icon="o-rectangle-stack" />
        <x-tallui-stat title="Workflows" value="4" desc="Transactional create flows" icon="o-bolt" />
    </div>

    <x-tallui-card title="Master Data" subtitle="Open the CRUD screens for inventory master tables." icon="o-squares-2x2" :shadow="true">
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
            @foreach ($entities as $entity => $definition)
                <x-tallui-card :title="$definition['label']" subtitle="Create, update, and review records." icon="o-folder" padding="compact" class="border border-base-200">
                    <x-tallui-button label="Open" icon="o-arrow-top-right-on-square" :link="route("inventory.entities.{$entity}.index")" class="btn-primary btn-sm" />
                </x-tallui-card>
            @endforeach
        </div>
    </x-tallui-card>
</div>
</x-layouts::app>


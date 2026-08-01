<div>
<x-tallui-page-header title="Sales Forecast" subtitle="Demand projection, cashflow outlook, and procurement requirement." icon="o-arrow-trending-up">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Reports', 'href' => route('inventory.reports.index')],
            ['label' => 'Forecast'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <div class="flex flex-wrap items-center gap-2">
            <x-tallui-select wire:model.live="lookbackDays" wire:loading.attr="disabled" wire:target="lookbackDays,forecastDays" class="select-sm">
                <option value="30">30 day history</option>
                <option value="90">90 day history</option>
                <option value="180">180 day history</option>
                <option value="365">365 day history</option>
            </x-tallui-select>
            <x-tallui-select wire:model.live="forecastDays" wire:loading.attr="disabled" wire:target="lookbackDays,forecastDays" class="select-sm">
                <option value="30">30 day horizon</option>
                <option value="90">90 day horizon</option>
                <option value="180">180 day horizon</option>
            </x-tallui-select>
            <span wire:loading wire:target="lookbackDays,forecastDays" class="flex items-center gap-1 text-xs text-base-content/60">
                <span class="loading loading-spinner loading-xs"></span>
                Recalculating…
            </span>
        </div>
    </x-slot:actions>
</x-tallui-page-header>

<div class="mb-6">
    <livewire:inventory-forecast-summary-card
        :lookback-days="$lookbackDays"
        :forecast-days="$forecastDays"
        lazy
        wire:key="forecast-summary-{{ $lookbackDays }}-{{ $forecastDays }}"
    />
</div>

<div class="mb-6">
    <livewire:inventory-forecast-product-customer-card
        :lookback-days="$lookbackDays"
        :forecast-days="$forecastDays"
        lazy
        wire:key="forecast-product-customer-{{ $lookbackDays }}-{{ $forecastDays }}"
    />
</div>

<livewire:inventory-forecast-geo-card
    :lookback-days="$lookbackDays"
    :forecast-days="$forecastDays"
    lazy
    wire:key="forecast-geo-{{ $lookbackDays }}-{{ $forecastDays }}"
/>
</div>

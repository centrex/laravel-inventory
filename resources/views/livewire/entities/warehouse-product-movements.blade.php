<div>
<x-tallui-notification />

<x-tallui-page-header
    title="Stock Movements"
    subtitle="{{ $stock->warehouse?->name }} — {{ $stock->product?->name }}{{ $stock->variant ? ' / ' . $stock->variant->sku : '' }}"
    icon="o-arrows-right-left"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Warehouse Stock', 'href' => route('inventory.entities.warehouse-products.index')],
            ['label' => 'Movements'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-button label="Back to Warehouse Stock" icon="o-arrow-left" :link="route('inventory.entities.warehouse-products.index')" class="btn-ghost btn-sm" wire:navigate />
    </x-slot:actions>
</x-tallui-page-header>

<div class="stats shadow w-full mb-4">
    <x-tallui-stat title="On Hand" value="{{ rtrim(rtrim(number_format((float) $stock->qty_on_hand, 4, '.', ''), '0'), '.') }}" icon="o-cube" />
    <x-tallui-stat title="Reserved" value="{{ rtrim(rtrim(number_format((float) $stock->qty_reserved, 4, '.', ''), '0'), '.') }}" icon="o-lock-closed" />
    <x-tallui-stat title="In Transit" value="{{ rtrim(rtrim(number_format((float) $stock->qty_in_transit, 4, '.', ''), '0'), '.') }}" icon="o-truck" />
    <x-tallui-stat title="WAC" value="{{ number_format((float) $stock->wac_amount, 2) }}" icon="o-banknotes" />
</div>

<x-tallui-card title="Movement History" subtitle="Append-only audit trail — every change here has a matching before/after quantity." icon="o-clock" :shadow="true">
    <x-slot:actions>
        <div class="flex items-center gap-2">
            <input type="date" wire:model.live="from" class="input input-bordered input-sm" />
            <span class="text-base-content/50 text-sm">to</span>
            <input type="date" wire:model.live="to" class="input input-bordered input-sm" />
            @if ($from !== '' || $to !== '')
                <x-tallui-button label="Clear" wire:click="resetFilters" class="btn-ghost btn-sm" />
            @endif
        </div>
    </x-slot:actions>

    @if ($movements->isEmpty())
        <x-tallui-empty-state title="No movements yet" description="Nothing has moved stock for this warehouse/product/variant combination in the selected range." icon="o-clock" size="md" />
    @else
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                        <th>Date</th>
                        <th>Type</th>
                        <th class="text-right">Qty</th>
                        <th class="text-right">Before</th>
                        <th class="text-right">After</th>
                        <th class="text-right">Unit Cost</th>
                        <th class="text-right">WAC</th>
                        <th>Reference</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($movements as $movement)
                        <tr>
                            <td class="whitespace-nowrap">{{ $movement->moved_at?->format('Y-m-d H:i') }}</td>
                            <td>
                                <x-tallui-badge :type="$movement->direction === 'in' ? 'success' : 'error'">
                                    {{ $movement->movement_type->label() }}
                                </x-tallui-badge>
                            </td>
                            <td class="text-right font-mono {{ $movement->direction === 'in' ? 'text-success' : 'text-error' }}">
                                {{ $movement->direction === 'in' ? '+' : '-' }}{{ rtrim(rtrim(number_format((float) $movement->qty, 4, '.', ''), '0'), '.') }}
                            </td>
                            <td class="text-right font-mono">{{ rtrim(rtrim(number_format((float) $movement->qty_before, 4, '.', ''), '0'), '.') }}</td>
                            <td class="text-right font-mono">{{ rtrim(rtrim(number_format((float) $movement->qty_after, 4, '.', ''), '0'), '.') }}</td>
                            <td class="text-right font-mono">{{ $movement->unit_cost_amount !== null ? number_format((float) $movement->unit_cost_amount, 2) : '—' }}</td>
                            <td class="text-right font-mono">{{ $movement->wac_amount !== null ? number_format((float) $movement->wac_amount, 2) : '—' }}</td>
                            <td class="text-xs text-base-content/60">
                                @if ($movement->reference_type)
                                    {{ class_basename($movement->reference_type) }} #{{ $movement->reference_id }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-xs text-base-content/60">{{ $movement->notes ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $movements->links() }}
        </div>
    @endif
</x-tallui-card>
</div>

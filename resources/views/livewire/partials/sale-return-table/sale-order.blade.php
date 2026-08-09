@if ($row->saleOrder)
    <a href="{{ route('inventory.sale-orders.show', ['recordId' => $row->saleOrder->id]) }}" wire:navigate class="font-mono text-sm text-primary hover:underline">
        {{ $row->saleOrder->so_number }}
    </a>
@else
    <span class="text-base-content/40">—</span>
@endif

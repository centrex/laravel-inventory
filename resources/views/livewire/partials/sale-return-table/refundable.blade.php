@php($memo = $row->creditMemo)
@if (!$memo)
    <span class="text-base-content/40 text-xs">Not credited</span>
@elseif ($memo->status->value === 'draft')
    <x-tallui-badge type="neutral" size="sm">Draft</x-tallui-badge>
@elseif (in_array($memo->status->value, ['issued', 'partially_refunded'], true) && $memo->refundable_amount > 0)
    <div>
        <x-tallui-badge type="warning" size="sm">Refundable</x-tallui-badge>
        <div class="text-xs font-mono text-base-content/70 mt-0.5">{{ number_format((float) $memo->refundable_amount, 2) }}</div>
    </div>
@elseif ($memo->status->value === 'refunded')
    <x-tallui-badge type="success" size="sm">Refunded</x-tallui-badge>
@elseif ($memo->status->value === 'void')
    <x-tallui-badge type="error" size="sm">Void</x-tallui-badge>
@else
    <span class="text-base-content/40 text-xs">—</span>
@endif

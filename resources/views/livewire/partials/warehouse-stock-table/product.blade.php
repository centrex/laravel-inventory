<div class="font-medium">{{ $row->product?->name ?? '—' }}</div>
<div class="flex flex-row gap-2">
@if ($row->variant)
    <div class="text-xs text-base-content/50">{{ $row->variant->name }}</div>
@endif
@if ($row->sku)
    <div class="text-xs font-mono text-red-300">{{ $row->sku }}</div>
@endif
</div>
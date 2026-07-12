<div class="font-medium">{{ $row->product?->name ?? '—' }}</div>
@if ($row->variant)
    <div class="text-xs text-base-content/50">{{ $row->variant->name }}</div>
@endif

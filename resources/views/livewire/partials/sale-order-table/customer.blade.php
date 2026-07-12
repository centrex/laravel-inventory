@if ($row->customer)
    <div class="font-medium">{{ $row->customer->organization_name ?: $row->customer->name }}</div>
    @if ($row->customer->organization_name)
        <div class="text-xs text-base-content/50">{{ $row->customer->name }}</div>
    @endif
@else
    <span class="text-base-content/40">Walk-in</span>
@endif

@if (is_bool($value))
    <x-tallui-badge :type="$value ? 'success' : 'neutral'">
        {{ $value ? 'Yes' : 'No' }}
    </x-tallui-badge>
@elseif (is_array($value))
    <span class="font-mono text-xs text-base-content/60">{{ json_encode($value) }}</span>
@elseif (strlen((string) $value) > 60)
    <span title="{{ $value }}">{{ substr($value, 0, 57) }}…</span>
@else
    {{ $value ?? '—' }}
@endif

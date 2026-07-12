@php
    $routeBase = $row->document_type === 'quotation' ? 'inventory.quotations' : 'inventory.sale-orders';
@endphp
<a href="{{ route($routeBase . '.show', ['recordId' => $row->getKey()]) }}" wire:navigate class="font-mono text-sm font-semibold text-primary hover:underline">
    {{ $value }}
</a>

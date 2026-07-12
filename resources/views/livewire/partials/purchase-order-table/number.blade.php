@php
    $routeBase = $row->document_type === 'requisition' ? 'inventory.requisitions' : 'inventory.purchase-orders';
@endphp
<a href="{{ route($routeBase . '.show', ['recordId' => $row->getKey()]) }}" wire:navigate class="font-mono text-sm font-semibold text-primary hover:underline">
    {{ $value }}
</a>

<a href="{{ route('inventory.purchase-returns.show', ['recordId' => $row->getKey()]) }}" wire:navigate class="font-mono text-sm font-semibold text-primary hover:underline">
    {{ $value }}
</a>

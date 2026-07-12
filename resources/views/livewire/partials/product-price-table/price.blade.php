@if ($price)
    <span class="text-sm">{{ number_format((float) $price->price_amount, 2) }}</span>
@else
    <span class="text-base-content/30">—</span>
@endif

<div>
<x-tallui-card
    title="Draft Sale Orders"
    subtitle="Not yet confirmed — excluded from the trend and revenue figures above."
    icon="o-document-text"
    :shadow="true"
    class="mb-6"
>
    <x-slot:actions>
        @can('inventory.sale-orders.view')
        <x-tallui-button
            label="View All"
            icon="o-arrow-top-right-on-square"
            :link="route('inventory.sale-orders.index', ['f' => ['status' => 'draft']])"
            class="btn-ghost btn-sm"
        />
        @endcan
    </x-slot:actions>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 mb-5">
        <div class="rounded-2xl border border-base-200 bg-base-100 p-4">
            <div class="text-xs font-semibold text-base-content/50 uppercase mb-2">Pending Orders</div>
            <div class="text-2xl font-bold text-base-content">{{ number_format($draftSaleOrders['count']) }}</div>
            <div class="mt-1 text-xs text-base-content/50">awaiting confirmation</div>
        </div>
        <div class="rounded-2xl border border-base-200 bg-base-100 p-4">
            <div class="text-xs font-semibold text-base-content/50 uppercase mb-2">Pending Value</div>
            <div class="text-2xl font-bold text-warning">{{ number_format($draftSaleOrders['total'], 2) }}</div>
            <div class="mt-1 text-xs text-base-content/50">not yet recognized as revenue</div>
        </div>
    </div>

    @if (count($draftSaleOrders['recent']) > 0)
        <div class="overflow-x-auto">
            <table class="table table-sm w-full">
                <thead>
                    <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                        <th>Order</th>
                        <th>Customer</th>
                        <th>Date</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($draftSaleOrders['recent'] as $draftOrder)
                        <tr class="even:bg-base-200/50 hover:bg-base-200">
                            <td>
                                @can('inventory.sale-orders.view')
                                <a href="{{ route('inventory.sale-orders.show', ['recordId' => $draftOrder['id']]) }}" class="font-semibold text-primary hover:underline" wire:navigate>
                                    {{ $draftOrder['so_number'] }}
                                </a>
                                @else
                                    {{ $draftOrder['so_number'] }}
                                @endcan
                            </td>
                            <td>{{ $draftOrder['customer_name'] ?? '—' }}</td>
                            <td>{{ $draftOrder['ordered_at'] ?? '—' }}</td>
                            <td>{{ number_format($draftOrder['total_amount'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <x-tallui-empty-state
            title="No draft sale orders"
            description="Every sale order has moved past draft."
            icon="o-check-circle"
            size="sm"
        />
    @endif
</x-tallui-card>
</div>

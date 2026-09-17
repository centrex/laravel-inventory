<div>
<x-tallui-notification />

<x-tallui-page-header title="Payments" subtitle="Sale and purchase payments recorded in accounting, mirrored here for reporting." icon="o-banknotes">
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Payments'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-tallui-button label="Record Payment" icon="o-plus" :link="route('inventory.payments.create')" class="btn-primary btn-sm" />
            <div class="w-44">
                <x-tallui-select wire:model.live="direction" class="select-sm">
                    <option value="">All directions</option>
                    <option value="sale">Sale</option>
                    <option value="purchase">Purchase</option>
                </x-tallui-select>
            </div>
        </div>
    </x-slot:actions>
</x-tallui-page-header>

<x-tallui-card padding="none" :shadow="true">
    <div class="overflow-x-auto">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                    <th class="pl-5">Number</th>
                    <th>Direction</th>
                    <th>Party</th>
                    <th>Order</th>
                    <th>Date</th>
                    <th>Method</th>
                    <th class="pr-5 text-right">Amount</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse ($payments as $payment)
                    <tr class="even:bg-base-200/50 hover:bg-base-200">
                        <td class="pl-5 font-mono font-semibold">{{ $payment->payment_number ?? '—' }}</td>
                        <td>
                            <x-tallui-badge :type="$payment->direction === 'sale' ? 'success' : 'info'">
                                {{ ucfirst($payment->direction) }}
                            </x-tallui-badge>
                        </td>
                        <td>{{ $payment->customer?->name ?? $payment->supplier?->name ?? '—' }}</td>
                        <td>{{ $payment->saleOrder?->so_number ?? $payment->purchaseOrder?->po_number ?? '—' }}</td>
                        <td>{{ $payment->payment_date?->format('Y-m-d') ?? '—' }}</td>
                        <td>{{ $payment->payment_method ? ucfirst(str_replace('_', ' ', $payment->payment_method)) : '—' }}</td>
                        <td class="pr-5 text-right font-semibold">{{ number_format((float) $payment->amount, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="py-8">
                            <x-tallui-empty-state title="No payments yet" description="Payments recorded against sale or purchase orders will appear here." icon="o-banknotes" size="sm">
                                <x-tallui-button label="Record Payment" icon="o-plus" :link="route('inventory.payments.create')" class="btn-primary" />
                            </x-tallui-empty-state>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($payments->hasPages())
        <div class="px-5 py-3 border-t border-base-200">
            {{ $payments->links() }}
        </div>
    @endif
</x-tallui-card>
</div>

<div class="space-y-4">
    <x-slot name="header">
        <div class="space-y-4">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                        <span>Logistics</span>
                        <span>/</span>
                        <span>Dispatch</span>
                    </div>
                    <h1 class="mt-2 text-2xl font-semibold text-gray-900 dark:text-white md:text-3xl">
                        Dispatch terminal
                    </h1>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-gray-500 dark:text-gray-400">
                        Process sale orders through picking, courier handoff, delivery, and customer tracking updates.
                    </p>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-zinc-800 dark:text-gray-200">
                            <x-tallui-icon name="o-check-circle" class="h-3.5 w-3.5 text-emerald-500" />
                            {{ $summary['confirmed'] }} Confirmed
                        </span>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-zinc-800 dark:text-gray-200">
                            <x-tallui-icon name="o-clipboard-document-check" class="h-3.5 w-3.5 text-blue-500" />
                            {{ $summary['processing'] }} Picking
                        </span>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-zinc-800 dark:text-gray-200">
                            <x-tallui-icon name="o-arrows-right-left" class="h-3.5 w-3.5 text-amber-500" />
                            {{ $summary['partial'] }} Partial
                        </span>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-zinc-800 dark:text-gray-200">
                            <x-tallui-icon name="o-truck" class="h-3.5 w-3.5 text-brand-500" />
                            {{ $summary['shipped'] }} Shipped
                        </span>
                    </div>
                </div>

                <a href="{{ route('inventory.sale-orders.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 transition hover:border-brand-300 hover:text-brand-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-gray-200 dark:hover:text-brand-300">
                    <x-tallui-icon name="o-document-text" class="h-4 w-4" />
                    Sale orders
                </a>
            </div>

            <div class="grid gap-3 lg:grid-cols-[1fr_220px_auto]">
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search order, customer, phone"
                    class="rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-900 outline-none focus:border-brand-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                />

                <select wire:model.live="status" class="rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-900 outline-none focus:border-brand-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>

                <button type="button" wire:click="$refresh" class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-500 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-brand-600">
                    <x-tallui-icon name="o-arrow-path" class="h-4 w-4" />
                    Refresh
                </button>
            </div>
        </div>
    </x-slot>

    @if (session('status'))
        <div
            x-data="{ show: true }"
            x-show="show"
            x-init="setTimeout(() => show = false, 4000)"
            x-transition.duration.500ms
            class="rounded-2xl border border-success-200 bg-success-50 px-4 py-3 text-sm text-success-700 dark:border-success-500/20 dark:bg-success-500/10 dark:text-success-300"
        >
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded-2xl border border-error-200 bg-error-50 px-4 py-3 text-sm text-error-700 dark:border-error-500/20 dark:bg-error-500/10 dark:text-error-300">
            {{ $errors->first() }}
        </div>
    @endif

    @unless ($modelDataReady)
        <div class="rounded-2xl border border-warning-200 bg-warning-50 px-4 py-3 text-sm text-warning-700 dark:border-warning-500/20 dark:bg-warning-500/10 dark:text-warning-300">
            Model data storage is not ready, so tracking metadata cannot be saved yet.
        </div>
    @endunless

    {{-- Tabs --}}
    @php
        $initialTab = $canViewDispatcherTab ? 'dispatcher' : 'sale-updater';
    @endphp
    <div x-data="{ tab: '{{ $initialTab }}' }">

        {{-- Tab bar --}}
        <div class="flex gap-1 rounded-2xl rounded-b-none border border-b-0 border-gray-200 bg-gray-50 px-3 py-2 dark:border-zinc-800 dark:bg-zinc-950/70">
            @if ($canViewDispatcherTab)
            <button
                type="button"
                @click="tab = 'dispatcher'"
                :class="tab === 'dispatcher'
                    ? 'bg-brand-500 text-white shadow-sm'
                    : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-zinc-800'"
                class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition"
            >
                <x-tallui-icon name="o-truck" class="h-4 w-4" />
                Dispatcher
            </button>
            @endif
            @if ($canViewUpdaterTab)
            <button
                type="button"
                @click="tab = 'sale-updater'"
                :class="tab === 'sale-updater'
                    ? 'bg-brand-500 text-white shadow-sm'
                    : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-zinc-800'"
                class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition"
            >
                <x-tallui-icon name="o-pencil-square" class="h-4 w-4" />
                Sale Updater
            </button>
            @endif
        </div>

        {{-- ───── Dispatcher tab ───── --}}
        @if ($canViewDispatcherTab)
        <div x-show="tab === 'dispatcher'" x-cloak>
            <section class="erp-panel overflow-hidden rounded-tl-none">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-zinc-800">
                        <thead class="bg-gray-50 dark:bg-zinc-950/70">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">Order</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">Customer</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">Parcel</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">Last updated</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-zinc-800">
                            @forelse ($orders as $order)
                                @php
                                    $meta = $metadata[$order->getKey()] ?? [];
                                    $currentParcel = $meta['parcel_status'] ?? '';
                                    $terminalStatuses = ['Delivery failed', 'Returned', 'Cancelled'];
                                    $isTerminal = in_array($currentParcel, $terminalStatuses);

                                    $nextAction = match (true) {
                                        in_array($currentParcel, ['', 'Order confirmed', 'Reserved for picking', 'Packed', 'Ready for courier'])
                                            => ['action' => 'dispatched',       'label' => 'Mark Dispatched',  'icon' => 'o-paper-airplane', 'class' => 'bg-amber-500 hover:bg-amber-600'],
                                        $currentParcel === 'Dispatched'
                                            => ['action' => 'out_for_delivery', 'label' => 'Out for Delivery', 'icon' => 'o-map-pin',         'class' => 'bg-blue-500 hover:bg-blue-600'],
                                        $currentParcel === 'Out for delivery'
                                            => ['action' => 'delivered',        'label' => 'Mark Delivered',   'icon' => 'o-check-circle',    'class' => 'bg-emerald-500 hover:bg-emerald-600'],
                                        default => null,
                                    };
                                @endphp
                                <tr wire:key="dw-{{ $order->getKey() }}">

                                    {{-- Order --}}
                                    <td class="px-4 py-4 align-top">
                                        <div class="font-mono text-sm font-semibold text-gray-900 dark:text-white">{{ $order->so_number }}</div>
                                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $order->ordered_at?->format('d M Y h:i A') ?? $order->created_at?->format('d M Y h:i A') }}</div>
                                        <div class="mt-2 inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-zinc-800 dark:text-gray-200">
                                            {{ $order->status?->label() ?? 'Unknown' }}
                                        </div>
                                        <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $order->items->count() }} items · {{ number_format((float) $order->total_local, 2) }} {{ $order->currency }}</div>
                                    </td>

                                    {{-- Customer --}}
                                    <td class="px-4 py-4 align-top">
                                        <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $order->customer?->organization_name ?? 'Walk-in customer' }}</div>
                                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $order->customer?->name }}</div>
                                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $order->customer?->phone ?? data_get($meta, 'shipping_address.phone', 'No phone') }}</div>
                                        <div class="mt-2 max-w-72 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ data_get($meta, 'shipping_address.formatted', $order->warehouse?->name ?? 'No shipping address') }}</div>
                                    </td>

                                    {{-- Parcel --}}
                                    <td class="px-4 py-4 align-top">
                                        <div class="font-mono text-sm font-semibold text-gray-900 dark:text-white">{{ $meta['tracking_number'] ?? 'No tracking assigned' }}</div>
                                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $meta['carrier'] ?? 'Connect Courier' }}</div>
                                        <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $meta['parcel_status'] ?? 'Tracking update pending' }}</div>
                                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $meta['location'] ?? $order->warehouse?->name ?? 'Fulfillment warehouse' }}</div>
                                    </td>

                                    {{-- Last updated --}}
                                    <td class="px-4 py-4 align-top">
                                        @if (!empty($meta['dispatch_updated_at']))
                                            <div class="text-sm text-gray-700 dark:text-gray-300">
                                                {{ \Carbon\Carbon::parse($meta['dispatch_updated_at'])->format('d M Y') }}
                                            </div>
                                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                {{ \Carbon\Carbon::parse($meta['dispatch_updated_at'])->format('h:i A') }}
                                            </div>
                                            @if (!empty($meta['dispatched_by']))
                                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">by {{ $meta['dispatched_by'] }}</div>
                                            @endif
                                            @if (!empty($meta['eta']))
                                                <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">ETA: {{ $meta['eta'] }}</div>
                                            @endif
                                        @else
                                            <span class="text-xs text-gray-400 dark:text-gray-500">Not updated yet</span>
                                        @endif
                                    </td>

                                    {{-- Actions --}}
                                    <td class="px-4 py-4 text-right align-top">
                                        <div class="flex flex-col items-end gap-2">
                                            <div class="flex items-center gap-1.5">
                                                <button
                                                    type="button"
                                                    wire:click="openDetailModalView({{ $order->getKey() }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="openDetailModalView({{ $order->getKey() }})"
                                                    class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition hover:border-brand-300 hover:text-brand-600 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-800 dark:text-gray-200"
                                                >
                                                    <x-tallui-icon name="o-eye" class="h-4 w-4" />
                                                    View
                                                </button>
                                                <button
                                                    type="button"
                                                    wire:click="openPrintNote({{ $order->getKey() }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="openPrintNote({{ $order->getKey() }})"
                                                    title="Print dispatch note"
                                                    class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-gray-200 bg-white text-gray-600 transition hover:border-brand-300 hover:text-brand-600 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-800 dark:text-gray-300"
                                                >
                                                    <x-tallui-icon name="o-printer" class="h-4 w-4" />
                                                </button>
                                            </div>
                                            @if ($nextAction)
                                                <button
                                                    type="button"
                                                    wire:click="quickDispatch({{ $order->getKey() }}, '{{ $nextAction['action'] }}')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="quickDispatch({{ $order->getKey() }}, '{{ $nextAction['action'] }}')"
                                                    wire:confirm="{{ $nextAction['label'] }} for {{ $order->so_number }}?"
                                                    class="inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-medium text-white transition disabled:cursor-not-allowed disabled:opacity-60 {{ $nextAction['class'] }}"
                                                >
                                                    <span wire:loading.remove wire:target="quickDispatch({{ $order->getKey() }}, '{{ $nextAction['action'] }}')">
                                                        <x-tallui-icon :name="$nextAction['icon']" class="h-4 w-4" />
                                                    </span>
                                                    <span wire:loading wire:target="quickDispatch({{ $order->getKey() }}, '{{ $nextAction['action'] }}')">
                                                        <x-tallui-icon name="o-arrow-path" class="h-4 w-4 animate-spin" />
                                                    </span>
                                                    {{ $nextAction['label'] }}
                                                </button>
                                            @else
                                                <span class="inline-flex items-center gap-1.5 rounded-xl bg-gray-100 px-3 py-2 text-sm font-medium text-gray-500 dark:bg-zinc-800 dark:text-gray-400">
                                                    <x-tallui-icon name="o-check-badge" class="h-4 w-4" />
                                                    {{ $isTerminal ? $currentParcel : 'Delivered' }}
                                                </span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                                        No sale orders found for this dispatch queue.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-gray-200 px-4 py-4 dark:border-zinc-800">
                    {{ $orders->links() }}
                </div>
            </section>
        </div>
        @endif

        {{-- ───── Sale Updater tab ───── --}}
        @if ($canViewUpdaterTab)
        <div x-show="tab === 'sale-updater'" x-cloak>
            <section class="erp-panel overflow-hidden rounded-tl-none">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-zinc-800">
                        <thead class="bg-gray-50 dark:bg-zinc-950/70">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">Order</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">Customer</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">Parcel</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">Last updated</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-zinc-800">
                            @forelse ($orders as $order)
                                @php
                                    $meta = $metadata[$order->getKey()] ?? [];
                                @endphp
                                <tr wire:key="su-{{ $order->getKey() }}">

                                    {{-- Order --}}
                                    <td class="px-4 py-4 align-top">
                                        <div class="font-mono text-sm font-semibold text-gray-900 dark:text-white">{{ $order->so_number }}</div>
                                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $order->ordered_at?->format('d M Y h:i A') ?? $order->created_at?->format('d M Y h:i A') }}</div>
                                        <div class="mt-2 inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-zinc-800 dark:text-gray-200">
                                            {{ $order->status?->label() ?? 'Unknown' }}
                                        </div>
                                        <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $order->items->count() }} items · {{ number_format((float) $order->total_local, 2) }} {{ $order->currency }}</div>
                                    </td>

                                    {{-- Customer --}}
                                    <td class="px-4 py-4 align-top">
                                        <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $order->customer?->organization_name ?? 'Walk-in customer' }}</div>
                                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $order->customer?->name }}</div>
                                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $order->customer?->phone ?? data_get($meta, 'shipping_address.phone', 'No phone') }}</div>
                                        <div class="mt-2 max-w-72 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ data_get($meta, 'shipping_address.formatted', $order->warehouse?->name ?? 'No shipping address') }}</div>
                                    </td>

                                    {{-- Parcel --}}
                                    <td class="px-4 py-4 align-top">
                                        <div class="font-mono text-sm font-semibold text-gray-900 dark:text-white">{{ $meta['tracking_number'] ?? 'No tracking assigned' }}</div>
                                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $meta['carrier'] ?? 'Connect Courier' }}</div>
                                        <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $meta['parcel_status'] ?? 'Tracking update pending' }}</div>
                                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $meta['location'] ?? $order->warehouse?->name ?? 'Fulfillment warehouse' }}</div>
                                    </td>

                                    {{-- Last updated --}}
                                    <td class="px-4 py-4 align-top">
                                        @if (!empty($meta['dispatch_updated_at']))
                                            <div class="text-sm text-gray-700 dark:text-gray-300">
                                                {{ \Carbon\Carbon::parse($meta['dispatch_updated_at'])->format('d M Y') }}
                                            </div>
                                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                {{ \Carbon\Carbon::parse($meta['dispatch_updated_at'])->format('h:i A') }}
                                            </div>
                                            @if (!empty($meta['dispatched_by']))
                                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">by {{ $meta['dispatched_by'] }}</div>
                                            @endif
                                            @if (!empty($meta['eta']))
                                                <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">ETA: {{ $meta['eta'] }}</div>
                                            @endif
                                        @else
                                            <span class="text-xs text-gray-400 dark:text-gray-500">Not updated yet</span>
                                        @endif
                                    </td>

                                    {{-- Actions --}}
                                    <td class="px-4 py-4 text-right align-top">
                                        <div class="flex items-center justify-end gap-2">
                                            <button
                                                type="button"
                                                wire:click="openDetailModal({{ $order->getKey() }})"
                                                wire:loading.attr="disabled"
                                                wire:target="openDetailModal({{ $order->getKey() }})"
                                                class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition hover:border-brand-300 hover:text-brand-600 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-800 dark:text-gray-200"
                                            >
                                                <x-tallui-icon name="o-eye" class="h-4 w-4" />
                                                View
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="openModal({{ $order->getKey() }})"
                                                wire:loading.attr="disabled"
                                                wire:target="openModal({{ $order->getKey() }})"
                                                class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-brand-500 px-3 py-2 text-sm font-medium text-white transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                <x-tallui-icon name="o-pencil-square" class="h-4 w-4" />
                                                Edit
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                                        No sale orders found for this dispatch queue.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-gray-200 px-4 py-4 dark:border-zinc-800">
                    {{ $orders->links() }}
                </div>
            </section>
        </div>
        @endif

    </div>{{-- /tabs --}}

    {{-- ───── Update modal ───── --}}
    @if ($modalOpen && $modalOrder && $modalOrderId)
        <div
            class="fixed inset-0 z-[9990] flex items-center justify-center p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="modal-title"
        >
            {{-- Backdrop --}}
            <div
                class="absolute inset-0 bg-black/50 backdrop-blur-sm"
                wire:click="closeModal"
            ></div>

            {{-- Panel --}}
            <div class="relative z-10 w-full max-w-2xl overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-zinc-900">

                {{-- Modal header --}}
                <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-zinc-800">
                    <div>
                        <h2 id="modal-title" class="font-mono text-base font-semibold text-gray-900 dark:text-white">
                            {{ $modalOrder->so_number }}
                        </h2>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ $modalOrder->customer?->organization_name ?? $modalOrder->customer?->name ?? 'Walk-in customer' }}
                            · {{ $modalOrder->status?->label() ?? 'Unknown' }}
                        </p>
                    </div>
                    <button
                        type="button"
                        wire:click="closeModal"
                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-zinc-800 dark:hover:text-gray-200"
                    >
                        <x-tallui-icon name="o-x-mark" class="h-5 w-5" />
                    </button>
                </div>

                {{-- Modal body --}}
                <form
                    wire:submit="updateOrder({{ $modalOrderId }})"
                    class="p-6"
                >
                    <div class="grid gap-4 sm:grid-cols-2">

                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-gray-600 dark:text-gray-400">Tracking number</label>
                            <input
                                wire:model="orderForms.{{ $modalOrderId }}.tracking_number"
                                placeholder="e.g. CTRX-250100001"
                                class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:border-brand-300 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                            />
                        </div>

                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-gray-600 dark:text-gray-400">Carrier</label>
                            <input
                                wire:model="orderForms.{{ $modalOrderId }}.carrier"
                                placeholder="e.g. Connect Courier"
                                class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:border-brand-300 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                            />
                        </div>

                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-gray-600 dark:text-gray-400">Parcel status</label>
                            <select
                                wire:model="orderForms.{{ $modalOrderId }}.parcel_status"
                                class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:border-brand-300 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                            >
                                @foreach ($parcelStatuses as $parcelStatus)
                                    <option value="{{ $parcelStatus }}">{{ $parcelStatus }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-gray-600 dark:text-gray-400">Order status</label>
                            <select
                                wire:model="orderForms.{{ $modalOrderId }}.order_status"
                                class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:border-brand-300 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                            >
                                @foreach ($orderStatusOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-gray-600 dark:text-gray-400">ETA</label>
                            <input
                                wire:model="orderForms.{{ $modalOrderId }}.eta"
                                type="date"
                                class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:border-brand-300 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                            />
                        </div>

                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-gray-600 dark:text-gray-400">Current location</label>
                            <input
                                wire:model="orderForms.{{ $modalOrderId }}.location"
                                placeholder="e.g. Sorting hub, Dhaka"
                                class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:border-brand-300 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                            />
                        </div>

                        <div class="sm:col-span-2">
                            <label class="mb-1.5 block text-xs font-medium text-gray-600 dark:text-gray-400">Dispatcher note</label>
                            <textarea
                                wire:model="orderForms.{{ $modalOrderId }}.dispatch_note"
                                rows="3"
                                placeholder="Optional notes for this dispatch update…"
                                class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:border-brand-300 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                            ></textarea>
                        </div>

                    </div>

                    {{-- Modal footer --}}
                    <div class="mt-6 flex items-center justify-end gap-3 border-t border-gray-200 pt-4 dark:border-zinc-800">
                        <button
                            type="button"
                            wire:click="closeModal"
                            class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 transition hover:border-gray-300 dark:border-zinc-700 dark:bg-zinc-800 dark:text-gray-200"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="updateOrder({{ $modalOrderId }})"
                            class="inline-flex items-center gap-2 rounded-xl bg-brand-500 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="updateOrder({{ $modalOrderId }})">
                                <x-tallui-icon name="o-check" class="h-4 w-4" />
                            </span>
                            <span wire:loading wire:target="updateOrder({{ $modalOrderId }})">
                                <x-tallui-icon name="o-arrow-path" class="h-4 w-4 animate-spin" />
                            </span>
                            Save changes
                        </button>
                    </div>
                </form>

            </div>
        </div>
    @endif

    {{-- ───── Sale detail modal ───── --}}
    @if ($detailModalOpen && $detailOrder)
        <div
            class="fixed inset-0 z-[9990] flex items-start justify-center overflow-y-auto p-4 pt-16"
            role="dialog"
            aria-modal="true"
            aria-labelledby="detail-modal-title"
        >
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" wire:click="closeDetailModal"></div>

            <div class="relative z-10 w-full max-w-3xl overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-zinc-900">

                {{-- Header --}}
                <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-zinc-800">
                    <div class="flex items-center gap-3">
                        <div>
                            <h2 id="detail-modal-title" class="font-mono text-base font-semibold text-gray-900 dark:text-white">
                                {{ $detailOrder->so_number }}
                            </h2>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ $detailOrder->ordered_at?->format('d M Y, h:i A') ?? $detailOrder->created_at?->format('d M Y, h:i A') }}
                                · {{ $detailOrder->warehouse?->name ?? '—' }}
                            </p>
                        </div>
                        <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-zinc-800 dark:text-gray-200">
                            {{ $detailOrder->status?->label() ?? 'Unknown' }}
                        </span>
                    </div>
                    <button
                        type="button"
                        wire:click="closeDetailModal"
                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-zinc-800 dark:hover:text-gray-200"
                    >
                        <x-tallui-icon name="o-x-mark" class="h-5 w-5" />
                    </button>
                </div>

                <div class="p-6 space-y-6">

                    {{-- Customer + Dispatch info --}}
                    <div class="grid gap-4 sm:grid-cols-2">

                        {{-- Customer --}}
                        <div class="rounded-xl border border-gray-200 p-4 dark:border-zinc-800">
                            <div class="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-gray-400">
                                <x-tallui-icon name="o-user" class="h-3.5 w-3.5" />
                                Customer
                            </div>
                            <div class="space-y-1.5 text-sm">
                                <div class="font-medium text-gray-900 dark:text-white">
                                    {{ $detailOrder->customer?->organization_name ?? $detailOrder->customer?->name ?? 'Walk-in customer' }}
                                </div>
                                @if ($detailOrder->customer?->organization_name && $detailOrder->customer?->name)
                                    <div class="text-gray-500 dark:text-gray-400">{{ $detailOrder->customer->name }}</div>
                                @endif
                                @if ($detailOrder->customer?->phone)
                                    <div class="text-gray-500 dark:text-gray-400">{{ $detailOrder->customer->phone }}</div>
                                @endif
                                @if ($detailOrder->customer?->email)
                                    <div class="text-gray-500 dark:text-gray-400">{{ $detailOrder->customer->email }}</div>
                                @endif
                                @php
                                    $shippingAddr = data_get($detailMeta, 'shipping_address.formatted');
                                @endphp
                                @if ($shippingAddr)
                                    <div class="mt-2 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $shippingAddr }}</div>
                                @endif
                            </div>
                        </div>

                        {{-- Dispatch info --}}
                        <div class="rounded-xl border border-gray-200 p-4 dark:border-zinc-800">
                            <div class="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-gray-400">
                                <x-tallui-icon name="o-truck" class="h-3.5 w-3.5" />
                                Dispatch
                            </div>
                            <div class="space-y-1.5 text-sm">
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-500 dark:text-gray-400">Tracking</span>
                                    <span class="font-mono font-medium text-gray-900 dark:text-white">{{ $detailMeta['tracking_number'] ?? '—' }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-500 dark:text-gray-400">Carrier</span>
                                    <span class="text-gray-900 dark:text-white">{{ $detailMeta['carrier'] ?? 'Connect Courier' }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-500 dark:text-gray-400">Parcel status</span>
                                    <span class="text-gray-900 dark:text-white">{{ $detailMeta['parcel_status'] ?? '—' }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-500 dark:text-gray-400">Location</span>
                                    <span class="text-gray-900 dark:text-white">{{ $detailMeta['location'] ?? $detailOrder->warehouse?->name ?? '—' }}</span>
                                </div>
                                @if (!empty($detailMeta['eta']))
                                    <div class="flex items-center justify-between">
                                        <span class="text-gray-500 dark:text-gray-400">ETA</span>
                                        <span class="text-gray-900 dark:text-white">{{ $detailMeta['eta'] }}</span>
                                    </div>
                                @endif
                                @if (!empty($detailMeta['dispatched_by']))
                                    <div class="flex items-center justify-between">
                                        <span class="text-gray-500 dark:text-gray-400">Updated by</span>
                                        <span class="text-gray-900 dark:text-white">{{ $detailMeta['dispatched_by'] }}</span>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Order items --}}
                    <div>
                        <div class="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-gray-400">
                            <x-tallui-icon name="o-shopping-cart" class="h-3.5 w-3.5" />
                            Items ({{ $detailOrder->items->count() }})
                        </div>
                        <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-zinc-800">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-zinc-800">
                                <thead class="bg-gray-50 dark:bg-zinc-950/70">
                                    <tr>
                                        <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-gray-400">Product</th>
                                        <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wider text-gray-400">Qty</th>
                                        <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wider text-gray-400">Unit price</th>
                                        <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wider text-gray-400">Discount</th>
                                        <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wider text-gray-400">Line total</th>
                                        @if ($detailShowPrices)<th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wider text-gray-400"></th>@endif
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-zinc-800">
                                    @foreach ($detailOrder->items as $item)
                                        <tr>
                                            <td class="px-4 py-3 text-sm">
                                                <div class="font-medium text-gray-900 dark:text-white">{{ $item->product?->name ?? '—' }}</div>
                                                @if ($item->variant)
                                                    <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $item->variant->name ?? $item->variant->sku ?? '' }}</div>
                                                @endif
                                                @if ($item->from_damaged)
                                                    <span class="mt-0.5 inline-flex rounded-full bg-error-50 px-1.5 py-0.5 text-xs font-medium text-error-700 dark:bg-error-500/10 dark:text-error-300">Damaged</span>
                                                @endif
                                            </td>
                                            <td class="px-4 py-3 text-right text-sm text-gray-900 dark:text-white">
                                                {{ number_format((float) $item->qty_ordered, 0) }}
                                                @if ((float) $item->qty_fulfilled > 0 && (float) $item->qty_fulfilled !== (float) $item->qty_ordered)
                                                    <div class="text-xs text-gray-400">({{ number_format((float) $item->qty_fulfilled, 0) }} fulfilled)</div>
                                                @endif
                                            </td>
                                            <td class="px-4 py-3 text-right font-mono text-sm text-gray-900 dark:text-white">
                                                {{ number_format((float) $item->unit_price_local, 2) }}
                                            </td>
                                            <td class="px-4 py-3 text-right text-sm text-gray-500 dark:text-gray-400">
                                                {{ (float) $item->discount_pct > 0 ? number_format((float) $item->discount_pct, 1) . '%' : '—' }}
                                            </td>
                                            <td class="px-4 py-3 text-right font-mono text-sm font-medium text-gray-900 dark:text-white">
                                                {{ number_format((float) $item->line_total_local, 2) }}
                                            </td>
                                            @if ($detailShowPrices)
                                            <td class="px-4 py-3 text-right">
                                                @if ($item->product_id)
                                                    <button
                                                        type="button"
                                                        wire:click="openPriceHistory({{ $item->product_id }})"
                                                        wire:loading.attr="disabled"
                                                        wire:target="openPriceHistory({{ $item->product_id }})"
                                                        class="inline-flex items-center gap-1 rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-600 transition hover:border-brand-300 hover:text-brand-600 disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-800 dark:text-gray-300"
                                                    >
                                                        <x-tallui-icon name="o-chart-bar" class="h-3 w-3" />
                                                        Prices
                                                    </button>
                                                @endif
                                            </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="bg-gray-50 dark:bg-zinc-950/70">
                                    <tr>
                                        <td colspan="4" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wider text-gray-400">Total</td>
                                        <td class="px-4 py-2.5 text-right font-mono text-sm font-semibold text-gray-900 dark:text-white">
                                            {{ number_format((float) $detailOrder->total_local, 2) }} {{ $detailOrder->currency }}
                                        </td>
                                        @if ($detailShowPrices)<td></td>@endif
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    @if (!empty($detailMeta['dispatch_note']))
                        <div class="rounded-xl border border-gray-200 px-4 py-3 dark:border-zinc-800">
                            <div class="mb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">Dispatcher note</div>
                            <p class="whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $detailMeta['dispatch_note'] }}</p>
                        </div>
                    @endif

                    {{-- ── Price history overlay ── --}}
                    @if ($priceHistoryProductId && isset($detailPriceHistory[$priceHistoryProductId]))
                    <div
                        class="fixed inset-0 z-[9995] flex items-start justify-center overflow-y-auto p-4 pt-20"
                        role="dialog"
                        aria-modal="true"
                    >
                        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" wire:click="closePriceHistory"></div>

                        <div class="relative z-10 w-full max-w-2xl overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-zinc-900">

                            {{-- Header --}}
                            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-zinc-800">
                                <div>
                                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">Price history</h2>
                                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $detailProductNames[$priceHistoryProductId] ?? '—' }}</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    {{-- Date range selector --}}
                                    <div class="flex items-center gap-0.5 rounded-xl border border-gray-200 p-1 dark:border-zinc-700">
                                        @foreach ([30 => '30d', 90 => '90d', 180 => '6m', 365 => '1y', 0 => 'All'] as $days => $label)
                                            <button
                                                type="button"
                                                wire:click="$set('priceHistoryDays', {{ $days }})"
                                                wire:loading.attr="disabled"
                                                wire:target="$set('priceHistoryDays', {{ $days }})"
                                                @class([
                                                    'rounded-lg px-2.5 py-1 text-xs font-medium transition',
                                                    'bg-brand-500 text-white shadow-sm' => $priceHistoryDays === $days,
                                                    'text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-zinc-800' => $priceHistoryDays !== $days,
                                                ])
                                            >{{ $label }}</button>
                                        @endforeach
                                    </div>
                                    <button
                                        type="button"
                                        wire:click="closePriceHistory"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-zinc-800 dark:hover:text-gray-200"
                                    >
                                        <x-tallui-icon name="o-x-mark" class="h-5 w-5" />
                                    </button>
                                </div>
                            </div>

                            {{-- Body --}}
                            <div class="p-6 space-y-5">
                                @if (isset($detailChartData[$priceHistoryProductId]))
                                    <livewire:tallui-line-chart
                                        wire:key="price-chart-{{ $priceHistoryProductId }}-{{ $priceHistoryDays }}"
                                        :series="$detailChartData[$priceHistoryProductId]['series']"
                                        :categories="$detailChartData[$priceHistoryProductId]['categories']"
                                        :height="220"
                                        :poll="0"
                                    />
                                @endif

                                @if ($detailPriceHistory[$priceHistoryProductId]->isNotEmpty())
                                    <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-zinc-800">
                                        <table class="min-w-full divide-y divide-gray-200 dark:divide-zinc-800">
                                            <thead class="bg-gray-50 dark:bg-zinc-950/70">
                                                <tr>
                                                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-gray-400">Tier</th>
                                                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-gray-400">Variant / Warehouse</th>
                                                    <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wider text-gray-400">Price</th>
                                                    <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wider text-gray-400">Cost</th>
                                                    <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wider text-gray-400">MOQ</th>
                                                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-gray-400">Effective</th>
                                                    <th class="px-4 py-2.5 text-center text-xs font-semibold uppercase tracking-wider text-gray-400">Active</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-200 dark:divide-zinc-800">
                                                @foreach ($detailPriceHistory[$priceHistoryProductId] as $price)
                                                    <tr @class(['opacity-50' => !$price->is_active])>
                                                        <td class="px-4 py-3">
                                                            <div class="text-xs font-medium text-gray-900 dark:text-white">{{ $price->price_tier_name ?? $price->price_tier_code }}</div>
                                                            @if ($price->is_damaged)
                                                                <span class="inline-flex rounded-full bg-error-50 px-1.5 py-0.5 text-xs text-error-700 dark:bg-error-500/10 dark:text-error-300">Damaged</span>
                                                            @endif
                                                        </td>
                                                        <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                                                            <div>{{ $price->variant?->name ?? $price->variant?->sku ?? 'All variants' }}</div>
                                                            <div>{{ $price->warehouse?->name ?? 'Global' }}</div>
                                                        </td>
                                                        <td class="px-4 py-3 text-right font-mono text-sm text-gray-900 dark:text-white">
                                                            {{ number_format((float) $price->price_local, 2) }}
                                                            <div class="text-xs text-gray-400">{{ $price->currency }}</div>
                                                        </td>
                                                        <td class="px-4 py-3 text-right font-mono text-sm text-gray-500 dark:text-gray-400">
                                                            {{ $price->cost_price ? number_format((float) $price->cost_price, 2) : '—' }}
                                                        </td>
                                                        <td class="px-4 py-3 text-right text-sm text-gray-500 dark:text-gray-400">
                                                            {{ $price->moq ?? 1 }}
                                                        </td>
                                                        <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                                                            <div>{{ $price->effective_from?->format('d M Y') ?? '—' }}</div>
                                                            <div>{{ $price->effective_to?->format('d M Y') ?? 'Open' }}</div>
                                                        </td>
                                                        <td class="px-4 py-3 text-center">
                                                            @if ($price->is_active)
                                                                <x-tallui-icon name="o-check-circle" class="inline h-4 w-4 text-emerald-500" />
                                                            @else
                                                                <x-tallui-icon name="o-x-circle" class="inline h-4 w-4 text-gray-300 dark:text-zinc-600" />
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @else
                                    <p class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">No price records found for this product.</p>
                                @endif
                            </div>

                            {{-- Footer --}}
                            <div class="flex justify-end border-t border-gray-200 px-6 py-4 dark:border-zinc-800">
                                <button
                                    type="button"
                                    wire:click="closePriceHistory"
                                    class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 transition hover:border-gray-300 dark:border-zinc-700 dark:bg-zinc-800 dark:text-gray-200"
                                >
                                    Close
                                </button>
                            </div>
                        </div>
                    </div>
                    @endif

                </div>

                {{-- Footer --}}
                <div class="flex justify-end border-t border-gray-200 px-6 py-4 dark:border-zinc-800">
                    <button
                        type="button"
                        wire:click="closeDetailModal"
                        class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 transition hover:border-gray-300 dark:border-zinc-700 dark:bg-zinc-800 dark:text-gray-200"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ───── Print dispatch note (screen: hidden; print: full page) ───── --}}
    <div
        x-data
        x-on:print-dispatch-note.window="
            $nextTick(() => {
                const note = document.getElementById('dispatch-print-note');

                if (!note) {
                    return;
                }

                const printWindow = window.open('', 'dispatch-note-print', 'width=900,height=1200');

                if (!printWindow) {
                    window.print();
                    return;
                }

                printWindow.document.write(`
                    <!doctype html>
                    <html>
                        <head>
                            <title>Dispatch Note</title>
                            <style>
                                @page { size: A4; margin: 9mm; }
                                * { box-sizing: border-box; }
                                body { margin: 0; color: #111827; font-family: Arial, sans-serif; font-size: 10.5px; line-height: 1.28; }
                                table { width: 100%; border-collapse: collapse; }
                                th, td { padding: 3px 5px; vertical-align: top; }
                                .print-note { width: 100%; max-height: 279mm; overflow: hidden; }
                                .print-header { border-bottom: 2px solid #111827; padding-bottom: 8px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: flex-end; }
                                .print-title { font-size: 20px; font-weight: 800; letter-spacing: -0.4px; }
                                .print-muted { color: #4b5563; }
                                .print-row { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 10px; }
                                .print-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 10px; }
                                .print-box { border: 1px solid #d1d5db; border-radius: 6px; padding: 8px; }
                                .print-section-title { color: #6b7280; font-size: 8.5px; font-weight: 800; letter-spacing: 0.08em; margin-bottom: 5px; text-transform: uppercase; }
                                .print-items { margin-bottom: 10px; }
                                .print-items thead tr { border-bottom: 2px solid #111827; }
                                .print-items tbody tr { border-bottom: 1px solid #eee; }
                                .print-items tfoot tr { border-top: 2px solid #111827; }
                                .print-signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 14px; border-top: 1px solid #d1d5db; padding-top: 12px; }
                                .print-line { display: inline-block; border-bottom: 1px solid #6b7280; width: 135px; }
                            </style>
                        </head>
                        <body>${note.innerHTML}</body>
                    </html>
                `);
                printWindow.document.close();
                printWindow.focus();
                printWindow.print();
                printWindow.close();
            })
        "
    ></div>

    @if ($printOrder)
    <style>
        @media screen {
            #dispatch-print-note { display: none !important; }
        }
        @media print {
            @page { size: A4; margin: 15mm; }
            body * { visibility: hidden !important; }
            #dispatch-print-note { display: block !important; visibility: visible !important; position: fixed; inset: 0; padding: 0; background: #fff; }
            #dispatch-print-note * { visibility: visible !important; }
        }
    </style>

    <div id="dispatch-print-note" class="print-note font-sans text-gray-900">

        {{-- Page header --}}
        <div class="print-header">
            <div>
                <div class="print-title">DISPATCH NOTE</div>
                <div class="print-muted">Printed: {{ now()->format('d M Y, h:i A') }}</div>
            </div>
            <div style="text-align:right">
                <div style="font-size:15px;font-weight:700;font-family:monospace">{{ $printOrder->so_number }}</div>
                <div class="print-muted">{{ $printOrder->ordered_at?->format('d M Y') ?? $printOrder->created_at?->format('d M Y') }}</div>
            </div>
        </div>

        {{-- Order meta row --}}
        <div class="print-row">
            <div><span class="print-muted">Status:</span> <strong>{{ $printOrder->status?->label() ?? '—' }}</strong></div>
            <div><span class="print-muted">Warehouse:</span> <strong>{{ $printOrder->warehouse?->name ?? '—' }}</strong></div>
            @if (!empty($printMeta['tracking_number']))
            <div><span class="print-muted">Tracking:</span> <strong style="font-family:monospace">{{ $printMeta['tracking_number'] }}</strong></div>
            @endif
            @if (!empty($printMeta['carrier']))
            <div><span class="print-muted">Carrier:</span> <strong>{{ $printMeta['carrier'] }}</strong></div>
            @endif
        </div>

        {{-- Customer + Delivery two-column --}}
        <div class="print-grid">

            <div class="print-box">
                <div class="print-section-title">Customer / Ship to</div>
                <div style="font-size:12px;font-weight:700">{{ $printOrder->customer?->organization_name ?? $printOrder->customer?->name ?? 'Walk-in customer' }}</div>
                @if ($printOrder->customer?->organization_name && $printOrder->customer?->name)
                <div class="print-muted">{{ $printOrder->customer->name }}</div>
                @endif
                @if ($printOrder->customer?->phone)
                <div class="print-muted">{{ $printOrder->customer->phone }}</div>
                @endif
                @if ($printOrder->customer?->email)
                <div class="print-muted">{{ $printOrder->customer->email }}</div>
                @endif
                @php
                    $shippingAddr = data_get($printMeta, 'shipping_address.formatted');
                @endphp
                @if ($shippingAddr)
                <div class="print-muted" style="margin-top:3px">{{ $shippingAddr }}</div>
                @endif
            </div>

            <div class="print-box">
                <div class="print-section-title">Delivery info</div>
                @foreach ([
                    ['label' => 'Parcel status', 'value' => $printMeta['parcel_status'] ?? '—'],
                    ['label' => 'Carrier',        'value' => $printMeta['carrier'] ?? 'Connect Courier'],
                    ['label' => 'Tracking no.',   'value' => $printMeta['tracking_number'] ?? '—'],
                    ['label' => 'Location',       'value' => $printMeta['location'] ?? ($printOrder->warehouse?->name ?? '—')],
                    ['label' => 'ETA',            'value' => $printMeta['eta'] ?? '—'],
                ] as $row)
                <div style="display:flex;justify-content:space-between;margin-bottom:2px;gap:8px">
                    <span class="print-muted">{{ $row['label'] }}</span>
                    <span style="font-weight:500">{{ $row['value'] }}</span>
                </div>
                @endforeach
                @if (!empty($printMeta['dispatched_by']))
                <div style="display:flex;justify-content:space-between;margin-bottom:2px;gap:8px">
                    <span class="print-muted">Updated by</span>
                    <span style="font-weight:500">{{ $printMeta['dispatched_by'] }}</span>
                </div>
                @endif
            </div>
        </div>

        {{-- Items table --}}
        <div class="print-items">
            <div class="print-section-title">Order items</div>
            <table>
                <thead>
                    <tr>
                        <th style="text-align:left;font-weight:700">#</th>
                        <th style="text-align:left;font-weight:700">Product</th>
                        <th style="text-align:left;font-weight:700">Variant</th>
                        <th style="text-align:right;font-weight:700">Qty</th>
                        <th style="text-align:right;font-weight:700">Unit price</th>
                        <th style="text-align:right;font-weight:700">Line total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($printOrder->items->take(12) as $i => $item)
                    <tr>
                        <td class="print-muted">{{ $i + 1 }}</td>
                        <td>
                            {{ $item->product?->name ?? '—' }}
                            @if ($item->from_damaged)<span style="font-size:10px;color:#c00"> [Damaged]</span>@endif
                        </td>
                        <td class="print-muted">{{ $item->variant?->name ?? $item->variant?->sku ?? '—' }}</td>
                        <td style="text-align:right">{{ number_format((float) $item->qty_ordered, 0) }}</td>
                        <td style="text-align:right;font-family:monospace">{{ number_format((float) $item->unit_price_local, 2) }}</td>
                        <td style="text-align:right;font-family:monospace;font-weight:700">{{ number_format((float) $item->line_total_local, 2) }}</td>
                    </tr>
                    @endforeach
                    @if ($printOrder->items->count() > 12)
                    <tr>
                        <td colspan="6" class="print-muted" style="text-align:center">+ {{ $printOrder->items->count() - 12 }} more items</td>
                    </tr>
                    @endif
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" style="text-align:right;font-weight:800;font-size:12px">Total</td>
                        <td style="text-align:right;font-family:monospace;font-weight:800;font-size:12px">{{ number_format((float) $printOrder->total_local, 2) }} {{ $printOrder->currency }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        {{-- Dispatcher note --}}
        @if (!empty($printMeta['dispatch_note']))
        <div class="print-box" style="margin-bottom:10px">
            <div class="print-section-title">Dispatcher note</div>
            <div style="white-space:pre-line">{{ \Illuminate\Support\Str::limit($printMeta['dispatch_note'], 320) }}</div>
        </div>
        @endif

        {{-- Signature block --}}
        <div class="print-signatures">
            <div>
                <div class="print-section-title">Dispatched by</div>
                <div>Name: <span class="print-line">&nbsp;</span></div>
                <div style="margin-top:9px">Signature: <span class="print-line">&nbsp;</span></div>
                <div style="margin-top:9px">Date: <span class="print-line">&nbsp;</span></div>
            </div>
            <div>
                <div class="print-section-title">Received by</div>
                <div>Name: <span class="print-line">&nbsp;</span></div>
                <div style="margin-top:9px">Signature: <span class="print-line">&nbsp;</span></div>
                <div style="margin-top:9px">Date: <span class="print-line">&nbsp;</span></div>
            </div>
        </div>

    </div>
    @endif


</div>

<x-layouts::app>
    <x-slot name="header">
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
                <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-500 dark:text-gray-400">
                    Process sale orders through picking, courier handoff, delivery, and customer tracking updates.
                </p>
            </div>

            <a href="{{ route('inventory.sale-orders.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 transition hover:border-brand-300 hover:text-brand-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-gray-200 dark:hover:text-brand-300">
                <x-tallui-icon name="o-document-text" class="h-4 w-4" />
                Sale orders
            </a>
        </div>
    </x-slot>

    <div class="space-y-4">
        @if (session('status'))
            <div class="rounded-2xl border border-success-200 bg-success-50 px-4 py-3 text-sm text-success-700 dark:border-success-500/20 dark:bg-success-500/10 dark:text-success-300">
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

        <section class="grid gap-3 md:grid-cols-4">
            @foreach ([
                ['label' => 'Confirmed', 'value' => $summary['confirmed'], 'icon' => 'o-check-circle'],
                ['label' => 'Picking', 'value' => $summary['processing'], 'icon' => 'o-clipboard-document-check'],
                ['label' => 'Partial', 'value' => $summary['partial'], 'icon' => 'o-arrows-right-left'],
                ['label' => 'Shipped', 'value' => $summary['shipped'], 'icon' => 'o-truck'],
            ] as $tile)
                <div class="erp-panel p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <div class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $tile['label'] }}</div>
                            <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $tile['value'] }}</div>
                        </div>
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
                            <x-tallui-icon :name="$tile['icon']" class="h-5 w-5" />
                        </span>
                    </div>
                </div>
            @endforeach
        </section>

        <section class="erp-panel p-5">
            <form method="GET" action="{{ route('inventory.dispatch.index') }}" class="grid gap-3 lg:grid-cols-[1fr_220px_auto]">
                <input
                    type="search"
                    name="search"
                    value="{{ $search }}"
                    placeholder="Search order, customer, phone"
                    class="rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-900 outline-none focus:border-brand-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                />

                <select name="status" class="rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-900 outline-none focus:border-brand-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                    @endforeach
                </select>

                <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-500 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-brand-600">
                    <x-tallui-icon name="o-magnifying-glass" class="h-4 w-4" />
                    Filter
                </button>
            </form>
        </section>

        <section class="erp-panel overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-zinc-800">
                    <thead class="bg-gray-50 dark:bg-zinc-950/70">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">Order</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">Customer</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">Parcel</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">Dispatch update</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-zinc-800">
                        @forelse ($orders as $order)
                            @php($meta = $metadata[$order->getKey()] ?? [])
                            @php($etaDate = filled($meta['eta'] ?? null) ? rescue(fn () => \Illuminate\Support\Carbon::parse($meta['eta'])->format('Y-m-d'), null, false) : null)
                            <tr>
                                <td class="px-4 py-4 align-top">
                                    <div class="font-mono text-sm font-semibold text-gray-900 dark:text-white">{{ $order->so_number }}</div>
                                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $order->ordered_at?->format('d M Y h:i A') ?? $order->created_at?->format('d M Y h:i A') }}</div>
                                    <div class="mt-2 inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-zinc-800 dark:text-gray-200">
                                        {{ $order->status?->label() ?? 'Unknown' }}
                                    </div>
                                    <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $order->items->count() }} items · {{ number_format((float) $order->total_local, 2) }} {{ $order->currency }}</div>
                                </td>

                                <td class="px-4 py-4 align-top">
                                    <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $order->customer?->organization_name ?? 'Walk-in customer' }}</div>
                                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $order->customer?->name }}</div>
                                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $order->customer?->phone ?? data_get($meta, 'shipping_address.phone', 'No phone') }}</div>
                                    <div class="mt-2 max-w-72 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ data_get($meta, 'shipping_address.formatted', $order->warehouse?->name ?? 'No shipping address') }}</div>
                                </td>

                                <td class="px-4 py-4 align-top">
                                    <div class="text-sm font-semibold text-gray-900 dark:text-white">{{ $meta['tracking_number'] ?? 'No tracking assigned' }}</div>
                                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $meta['carrier'] ?? 'Connect Courier' }}</div>
                                    <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $meta['parcel_status'] ?? 'Tracking update pending' }}</div>
                                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $meta['location'] ?? $order->warehouse?->name ?? 'Fulfillment warehouse' }}</div>
                                </td>

                                <td class="px-4 py-4 align-top">
                                    <form id="dispatch-update-{{ $order->getKey() }}" method="POST" action="{{ route('inventory.dispatch.update', $order) }}" class="grid min-w-[520px] gap-2 lg:grid-cols-2">
                                        @csrf
                                        @method('PATCH')

                                        <input name="tracking_number" value="{{ old("orders.{$order->getKey()}.tracking_number", $meta['tracking_number'] ?? '') }}" placeholder="Tracking number" class="rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:border-brand-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                                        <input name="carrier" value="{{ old("orders.{$order->getKey()}.carrier", $meta['carrier'] ?? 'Connect Courier') }}" placeholder="Carrier" class="rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:border-brand-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />

                                        <select name="parcel_status" class="rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:border-brand-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                                            @foreach ($parcelStatuses as $parcelStatus)
                                                <option value="{{ $parcelStatus }}" @selected(($meta['parcel_status'] ?? 'Order confirmed') === $parcelStatus)>{{ $parcelStatus }}</option>
                                            @endforeach
                                        </select>

                                        <select name="order_status" class="rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:border-brand-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                                            @foreach (['confirmed' => 'Confirmed', 'processing' => 'Processing', 'partial' => 'Partial', 'shipped' => 'Shipped', 'fulfilled' => 'Fulfilled', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'returned' => 'Returned'] as $value => $label)
                                                <option value="{{ $value }}" @selected($order->status?->value === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>

                                        <input name="eta" type="date" value="{{ old("orders.{$order->getKey()}.eta", $etaDate) }}" class="rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:border-brand-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                                        <input name="location" value="{{ old("orders.{$order->getKey()}.location", $meta['location'] ?? $order->warehouse?->name) }}" placeholder="Current location" class="rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:border-brand-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />

                                        <textarea name="dispatch_note" rows="2" placeholder="Dispatcher note" class="rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:border-brand-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white lg:col-span-2">{{ old("orders.{$order->getKey()}.dispatch_note", $meta['dispatch_note'] ?? '') }}</textarea>
                                    </form>
                                </td>

                                <td class="px-4 py-4 text-right align-top">
                                    <button form="dispatch-update-{{ $order->getKey() }}" type="submit" class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-500 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-brand-600">
                                        <x-tallui-icon name="o-check" class="h-4 w-4" />
                                        Save
                                    </button>
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
</x-layouts::app>

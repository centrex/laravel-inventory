<div>
<x-tallui-page-header
    title="{{ $customer->name }}"
    :subtitle="$customer->organization_name ?: ('Customer #' . $customer->code)"
    icon="o-user"
    :separator="true"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => 'Customers', 'href' => route('inventory.entities.customers.index')],
            ['label' => $customer->name],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-button
            label="Edit"
            icon="o-pencil-square"
            :link="route('inventory.entities.customers.edit', ['recordId' => $customer->getKey()])"
            class="btn-primary btn-sm"
            wire:navigate
        />
    </x-slot:actions>
</x-tallui-page-header>

<div class="space-y-5">

{{-- ── Customer info ─────────────────────────────────────────────────── --}}
<div class="grid grid-cols-1 gap-4 xl:grid-cols-3">

    <x-tallui-card title="Profile" icon="o-identification" :shadow="true">
        <div class="space-y-3 text-sm">
            @if ($customer->organization_name)
                <div class="flex justify-between gap-3">
                    <span class="shrink-0 text-base-content/50">Organization</span>
                    <span class="text-right font-medium">{{ $customer->organization_name }}</span>
                </div>
            @endif
            <div class="flex justify-between gap-3">
                <span class="shrink-0 text-base-content/50">Code</span>
                <span class="text-right font-mono font-medium">{{ $customer->code }}</span>
            </div>
            @if ($customer->email)
                <div class="flex justify-between gap-3">
                    <span class="shrink-0 text-base-content/50">Email</span>
                    <span class="text-right font-medium">{{ $customer->email }}</span>
                </div>
            @endif
            @if ($customer->phone)
                <div class="flex justify-between gap-3">
                    <span class="shrink-0 text-base-content/50">Phone</span>
                    <span class="text-right font-medium">{{ $customer->phone }}</span>
                </div>
            @endif
            @if ($customer->zone || $customer->area)
                <div class="flex justify-between gap-3">
                    <span class="shrink-0 text-base-content/50">Zone / Area</span>
                    <span class="text-right font-medium">
                        {{ implode(' / ', array_filter([$customer->zone, $customer->area])) }}
                    </span>
                </div>
            @endif
            <div class="flex justify-between gap-3">
                <span class="shrink-0 text-base-content/50">Price Tier</span>
                <span class="text-right font-medium">{{ $customer->price_tier_code ?? '—' }}</span>
            </div>
            <div class="flex justify-between gap-3">
                <span class="shrink-0 text-base-content/50">Currency</span>
                <span class="text-right font-mono font-medium">{{ $customer->currency }}</span>
            </div>
            <div class="flex justify-between gap-3">
                <span class="shrink-0 text-base-content/50">Status</span>
                <x-tallui-badge :type="$customer->is_active ? 'success' : 'neutral'">
                    {{ $customer->is_active ? 'Active' : 'Inactive' }}
                </x-tallui-badge>
            </div>
        </div>
    </x-tallui-card>

    {{-- Addresses --}}
    <x-tallui-card title="Addresses" icon="o-map-pin" class="xl:col-span-2" :shadow="true">
        @if ($addresses->isEmpty())
            <p class="text-sm text-base-content/50">No addresses on file.</p>
        @else
            <div class="divide-y divide-base-200">
                @foreach ($addresses as $address)
                    <div class="py-2.5 text-sm {{ $loop->first ? 'pt-0' : '' }}">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-medium">
                                    {{ $address->label ?: ucfirst($address->type ?? 'Address') }}
                                </div>
                                <div class="mt-0.5 text-base-content/60 whitespace-pre-line">{{ implode(', ', array_filter([
                                    $address->street,
                                    $address->street_extra,
                                    $address->city,
                                    $address->district,
                                    $address->state,
                                    $address->post_code,
                                    $address->country_code,
                                ])) }}</div>
                                @if ($address->contact_phone || $address->contact_email)
                                    <div class="mt-0.5 text-xs text-base-content/50">
                                        {{ implode(' · ', array_filter([$address->contact_phone, $address->contact_email])) }}
                                    </div>
                                @endif
                            </div>
                            <div class="flex shrink-0 flex-wrap gap-1">
                                @if ($address->is_primary)
                                    <x-tallui-badge type="primary" size="xs">Primary</x-tallui-badge>
                                @endif
                                @if ($address->is_billing)
                                    <x-tallui-badge type="info" size="xs">Billing</x-tallui-badge>
                                @endif
                                @if ($address->is_shipping)
                                    <x-tallui-badge type="success" size="xs">Shipping</x-tallui-badge>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
        <div class="mt-3 border-t border-base-200 pt-3">
            <x-tallui-button
                label="Manage Addresses"
                icon="o-pencil-square"
                :link="route('inventory.entities.customers.edit', ['recordId' => $customer->getKey()])"
                class="btn-ghost btn-xs"
                wire:navigate
            />
        </div>
    </x-tallui-card>

</div>

{{-- ── Account Access ────────────────────────────────────────────────── --}}
@can('inventory.master-data.manage')
    <livewire:inventory-manage-user-access :entity="'customers'" :record-id="$customer->getKey()" />
@endcan

{{-- ── Credit + Analytics ────────────────────────────────────────────── --}}
<div class="grid grid-cols-1 gap-4 xl:grid-cols-3">

    <div class="xl:col-span-1">
    <x-tallui-card title="Credit" subtitle="Current exposure and available headroom." icon="o-banknotes" :shadow="true">
        @php
            $creditLimit     = (float) ($customerCreditSnapshot['credit_limit_amount'] ?? 0);
            $creditExposure  = (float) ($customerCreditSnapshot['outstanding_exposure'] ?? 0);
            $creditAvailable = (float) ($customerCreditSnapshot['available_credit_amount'] ?? 0);
            $utilizationPct  = $creditLimit > 0 ? min(100, (int) round($creditExposure / $creditLimit * 100)) : 0;
        @endphp

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-base-200 bg-base-50 p-4">
                <div class="text-xs font-medium uppercase tracking-wide text-base-content/50">Limit</div>
                <div class="mt-1.5 text-xl font-bold tabular-nums">{{ number_format($creditLimit, 2) }}</div>
                <div class="mt-0.5 text-xs text-base-content/40">BDT</div>
            </div>
            <div class="rounded-xl border border-base-200 bg-base-50 p-4">
                <div class="text-xs font-medium uppercase tracking-wide text-base-content/50">Exposure</div>
                <div class="mt-1.5 text-xl font-bold tabular-nums {{ $utilizationPct >= 90 ? 'text-error' : ($utilizationPct >= 70 ? 'text-warning' : '') }}">
                    {{ number_format($creditExposure, 2) }}
                </div>
                <div class="mt-0.5 text-xs text-base-content/40">BDT</div>
            </div>
            <div class="rounded-xl border border-base-200 bg-base-50 p-4">
                <div class="text-xs font-medium uppercase tracking-wide text-base-content/50">Available</div>
                <div class="mt-1.5 text-xl font-bold tabular-nums {{ $creditAvailable < 0 ? 'text-error' : 'text-success' }}">
                    {{ number_format($creditAvailable, 2) }}
                </div>
                <div class="mt-0.5 text-xs text-base-content/40">BDT</div>
            </div>
        </div>

        @if ($creditLimit > 0)
            <div class="mt-4">
                <div class="mb-1 flex items-center justify-between text-xs text-base-content/50">
                    <span>Utilization</span>
                    <span class="font-semibold {{ $utilizationPct >= 90 ? 'text-error' : ($utilizationPct >= 70 ? 'text-warning' : 'text-success') }}">
                        {{ $utilizationPct }}%
                    </span>
                </div>
                <x-tallui-progress
                    :value="$utilizationPct"
                    :max="100"
                    :color="$utilizationPct >= 90 ? 'error' : ($utilizationPct >= 70 ? 'warning' : 'success')"
                    size="sm"
                />
            </div>
        @endif
    </x-tallui-card>
    </div>

    <div class="xl:col-span-2">
    <x-tallui-card title="Analytics" subtitle="Buying behaviour, CLV, RFM, and demand forecast." icon="o-presentation-chart-line" :shadow="true">
        @php
            $rfmLabel  = $customerAnalytics['rfm_label'] ?? 'Active';
            $churnRisk = $customerAnalytics['churn_risk'] ?? 'none';
            $rfmColor  = match ($rfmLabel) {
                'VIP'             => 'text-warning',
                'Loyal'           => 'text-success',
                'Cannot Lose'     => 'text-error',
                'At Risk'         => 'text-warning',
                'Lost'            => 'text-error',
                'Promising'       => 'text-info',
                'Potential Loyal' => 'text-primary',
                default           => '',
            };
            $churnColor = match ($churnRisk) {
                'high'   => 'text-error',
                'medium' => 'text-warning',
                'low'    => 'text-info',
                default  => 'text-success',
            };
            $churnLabel = match ($churnRisk) {
                'high'   => 'High',
                'medium' => 'Medium',
                'low'    => 'Low',
                default  => 'None',
            };
            $rfmR = $customerAnalytics['rfm_recency'] ?? 1;
            $rfmF = $customerAnalytics['rfm_frequency'] ?? 1;
            $rfmM = $customerAnalytics['rfm_monetary'] ?? 1;
        @endphp

        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <div class="rounded-xl border border-base-200 bg-base-50 p-3 text-center">
                <div class="text-2xl font-bold tabular-nums">{{ $customerAnalytics['all_time_orders'] ?? 0 }}</div>
                <div class="mt-0.5 text-xs text-base-content/50">All-time Orders</div>
            </div>
            <div class="rounded-xl border border-base-200 bg-base-50 p-3 text-center">
                <div class="truncate text-xl font-bold tabular-nums" title="{{ number_format((float) ($customerAnalytics['all_time_revenue'] ?? 0), 2) }} BDT">
                    {{ number_format((float) ($customerAnalytics['all_time_revenue'] ?? 0) / 1000, 1) }}k
                </div>
                <div class="mt-0.5 text-xs text-base-content/50">All-time Revenue</div>
            </div>
            <div class="rounded-xl border border-base-200 bg-base-50 p-3 text-center">
                <div class="truncate text-xl font-bold tabular-nums text-primary" title="{{ number_format((float) ($customerAnalytics['clv_simple'] ?? 0), 2) }} BDT">
                    {{ number_format((float) ($customerAnalytics['clv_simple'] ?? 0) / 1000, 1) }}k
                </div>
                <div class="mt-0.5 text-xs text-base-content/50">CLV ({{ $customerAnalytics['clv_lifespan_years'] ?? 1 }}yr)</div>
            </div>
            <div class="rounded-xl border border-base-200 bg-base-50 p-3 text-center">
                <div class="truncate text-xl font-bold tabular-nums text-info" title="{{ number_format((float) ($customerAnalytics['forecast_revenue'] ?? 0), 2) }} BDT">
                    {{ number_format((float) ($customerAnalytics['forecast_revenue'] ?? 0) / 1000, 1) }}k
                </div>
                <div class="mt-0.5 text-xs text-base-content/50">{{ $customerAnalytics['forecast_days'] ?? 90 }}-day Forecast</div>
            </div>
        </div>

        <div class="mt-3 rounded-xl border border-base-200 bg-base-50 p-3">
            <div class="mb-2 flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wide text-base-content/50">RFM Score</span>
                <span class="text-sm font-bold {{ $rfmColor }}">{{ $rfmLabel }}</span>
            </div>
            <div class="grid grid-cols-3 gap-2 text-center text-xs">
                <div>
                    <div class="text-base-content/50">Recency</div>
                    <div class="flex items-center justify-center gap-0.5 mt-1">
                        @for ($i = 1; $i <= 5; $i++)
                            <div class="h-2 w-full rounded-sm {{ $i <= $rfmR ? 'bg-primary' : 'bg-base-300' }}"></div>
                        @endfor
                    </div>
                    <div class="mt-0.5 font-semibold">{{ $rfmR }}/5</div>
                </div>
                <div>
                    <div class="text-base-content/50">Frequency</div>
                    <div class="flex items-center justify-center gap-0.5 mt-1">
                        @for ($i = 1; $i <= 5; $i++)
                            <div class="h-2 w-full rounded-sm {{ $i <= $rfmF ? 'bg-secondary' : 'bg-base-300' }}"></div>
                        @endfor
                    </div>
                    <div class="mt-0.5 font-semibold">{{ $rfmF }}/5</div>
                </div>
                <div>
                    <div class="text-base-content/50">Monetary</div>
                    <div class="flex items-center justify-center gap-0.5 mt-1">
                        @for ($i = 1; $i <= 5; $i++)
                            <div class="h-2 w-full rounded-sm {{ $i <= $rfmM ? 'bg-accent' : 'bg-base-300' }}"></div>
                        @endfor
                    </div>
                    <div class="mt-0.5 font-semibold">{{ $rfmM }}/5</div>
                </div>
            </div>
        </div>

        <div class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2.5 text-sm sm:grid-cols-4">
            <div>
                <div class="text-xs text-base-content/50">Segment</div>
                <div class="font-medium">{{ $customerAnalytics['segment'] ?? 'New' }}</div>
            </div>
            <div>
                <div class="text-xs text-base-content/50">Churn Risk</div>
                <div class="font-medium {{ $churnColor }}">{{ $churnLabel }}</div>
            </div>
            <div>
                <div class="text-xs text-base-content/50">Orders / Month</div>
                <div class="font-medium">{{ number_format((float) ($customerAnalytics['orders_per_month'] ?? 0), 1) }}</div>
            </div>
            <div>
                <div class="text-xs text-base-content/50">Avg Interval</div>
                <div class="font-medium">
                    @if (($customerAnalytics['avg_purchase_interval'] ?? null) !== null)
                        {{ $customerAnalytics['avg_purchase_interval'] }} days
                    @else
                        —
                    @endif
                </div>
            </div>
            <div>
                <div class="text-xs text-base-content/50">Avg Order Value</div>
                <div class="font-medium">{{ number_format((float) ($customerAnalytics['avg_order_value'] ?? 0), 2) }}</div>
            </div>
            <div>
                <div class="text-xs text-base-content/50">Last Order</div>
                <div class="font-medium">
                    {{ $customerAnalytics['last_order_at'] ?? '—' }}
                    @if (($customerAnalytics['days_since_order'] ?? null) !== null)
                        <span class="text-xs text-base-content/40">({{ $customerAnalytics['days_since_order'] }}d ago)</span>
                    @endif
                </div>
            </div>
            <div>
                <div class="text-xs text-base-content/50">First Order</div>
                <div class="font-medium">{{ $customerAnalytics['first_order_at'] ?? '—' }}</div>
            </div>
            <div>
                <div class="text-xs text-base-content/50">Customer Age</div>
                <div class="font-medium">
                    @php $ageDays = (int) ($customerAnalytics['customer_age_days'] ?? 0); @endphp
                    @if ($ageDays >= 365)
                        {{ round($ageDays / 365, 1) }} yrs
                    @elseif ($ageDays > 0)
                        {{ $ageDays }} days
                    @else
                        —
                    @endif
                </div>
            </div>
            <div>
                <div class="text-xs text-base-content/50">Demography</div>
                <div class="font-medium">{{ $customer->demographic_segment ?? '—' }}</div>
            </div>
            <div>
                <div class="text-xs text-base-content/50">Zone</div>
                <div class="font-medium">{{ $customer->zone ?? '—' }}</div>
            </div>
            <div>
                <div class="text-xs text-base-content/50">Area</div>
                <div class="font-medium">{{ $customer->area ?? '—' }}</div>
            </div>
            <div>
                <div class="text-xs text-base-content/50">Distinct Products</div>
                <div class="font-medium">{{ $customerAnalytics['distinct_products'] ?? 0 }}</div>
            </div>
        </div>

        @if (!empty($customerAnalytics['monthly_trend']))
            <div class="mt-4">
                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-base-content/50">
                    Revenue Trend — last {{ $customerAnalytics['lookback_days'] ?? 180 }} days
                </div>
                @php $trendMax = max(1, max(array_column($customerAnalytics['monthly_trend'], 'revenue'))); @endphp
                <div class="flex items-end gap-1.5" style="height: 60px">
                    @foreach ($customerAnalytics['monthly_trend'] as $month)
                        @php $barPct = (float) $month['revenue'] / $trendMax * 100; @endphp
                        <div class="group relative flex flex-1 flex-col items-center justify-end" style="height: 60px">
                            <div
                                class="w-full rounded-t bg-primary/70 transition-all group-hover:bg-primary"
                                style="height: {{ max(4, (int) $barPct) }}%"
                                title="{{ $month['month'] }}: {{ number_format($month['revenue'], 0) }} BDT ({{ $month['orders_count'] }} orders)"
                            ></div>
                            <div class="mt-1 text-center text-[10px] leading-none text-base-content/40">{{ $month['month'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if (!empty($customerAnalytics['top_products']))
            <div class="mt-4">
                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-base-content/50">Top Products (last {{ $customerAnalytics['lookback_days'] ?? 180 }} days)</div>
                @php $topMax = max(1, max(array_column($customerAnalytics['top_products'], 'revenue'))); @endphp
                <div class="space-y-2">
                    @foreach ($customerAnalytics['top_products'] as $product)
                        @php $pct = (int) round((float) $product['revenue'] / $topMax * 100); @endphp
                        <div class="flex items-center gap-2 text-xs">
                            <div class="w-28 truncate font-medium text-base-content/80" title="{{ $product['name'] }}">{{ $product['name'] }}</div>
                            <div class="flex-1 rounded-full bg-base-200" style="height: 6px">
                                <div class="h-full rounded-full bg-secondary" style="width: {{ $pct }}%"></div>
                            </div>
                            <div class="w-24 text-right text-base-content/60">
                                {{ number_format($product['revenue'], 0) }} BDT
                                <span class="text-base-content/40">(×{{ number_format($product['qty'], 0) }})</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </x-tallui-card>
    </div>

</div>

{{-- ── Order History ──────────────────────────────────────────────────── --}}
<x-tallui-card title="Order History" subtitle="Recent sale orders for this customer." icon="o-clock" :shadow="true">
    {{-- Mobile --}}
    <div class="space-y-3 sm:hidden">
        @forelse ($customerHistory as $saleOrder)
            @php
                $soStatus    = $saleOrder->status?->value ?? '';
                $statusBadge = match ($soStatus) {
                    'fulfilled'  => 'success',
                    'cancelled'  => 'error',
                    'partial'    => 'warning',
                    'processing' => 'info',
                    'confirmed'  => 'primary',
                    default      => 'neutral',
                };
            @endphp
            <div class="rounded-xl border border-base-200 bg-base-50/50 p-3 text-sm">
                <div class="flex items-center justify-between gap-2">
                    @if (Route::has('inventory.sale-orders.show'))
                        <a href="{{ route('inventory.sale-orders.show', ['recordId' => $saleOrder->getKey()]) }}" class="font-semibold text-primary hover:underline" wire:navigate>
                            {{ $saleOrder->so_number }}
                        </a>
                    @else
                        <span class="font-semibold">{{ $saleOrder->so_number }}</span>
                    @endif
                    <x-tallui-badge :type="$statusBadge" size="sm">{{ $saleOrder->status?->label() ?? '—' }}</x-tallui-badge>
                </div>
                <div class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-base-content/60">
                    <div>
                        <div class="uppercase tracking-wide">Warehouse</div>
                        <div class="font-medium text-base-content/80">{{ $saleOrder->warehouse?->name ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="uppercase tracking-wide">Ordered At</div>
                        <div class="font-medium text-base-content/80">{{ $saleOrder->ordered_at?->format('d M Y') ?? '—' }}</div>
                    </div>
                </div>
                <div class="mt-2 flex items-center justify-between border-t border-base-200 pt-2">
                    <span class="text-xs text-base-content/50">Total</span>
                    <span class="font-semibold">{{ number_format((float) $saleOrder->total_amount, 2) }} BDT</span>
                </div>
            </div>
        @empty
            <x-tallui-empty-state title="No orders yet" description="This customer has no sale history." icon="o-shopping-cart" size="sm" />
        @endforelse
    </div>

    {{-- Tablet+ --}}
    <div class="hidden overflow-x-auto sm:block">
        <table class="table table-sm w-full">
            <thead>
                <tr class="bg-base-300 text-xs text-base-content/60 uppercase tracking-wide border-b border-base-300">
                    <th class="pl-4">Order</th>
                    <th>Warehouse</th>
                    <th>Ordered At</th>
                    <th>Status</th>
                    <th class="pr-4 text-right">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-base-200">
                @forelse ($customerHistory as $saleOrder)
                    @php
                        $soStatus    = $saleOrder->status?->value ?? '';
                        $statusBadge = match ($soStatus) {
                            'fulfilled'  => 'success',
                            'cancelled'  => 'error',
                            'partial'    => 'warning',
                            'processing' => 'info',
                            'confirmed'  => 'primary',
                            default      => 'neutral',
                        };
                    @endphp
                    <tr class="even:bg-base-200/50 hover:bg-base-200/50">
                        <td class="pl-4 py-2.5 text-sm font-medium">
                            @if (Route::has('inventory.sale-orders.show'))
                                <a href="{{ route('inventory.sale-orders.show', ['recordId' => $saleOrder->getKey()]) }}" class="text-primary hover:underline" wire:navigate>
                                    {{ $saleOrder->so_number }}
                                </a>
                            @else
                                {{ $saleOrder->so_number }}
                            @endif
                        </td>
                        <td class="py-2.5 text-sm text-base-content/70">{{ $saleOrder->warehouse?->name ?? '—' }}</td>
                        <td class="py-2.5 text-sm text-base-content/70">{{ $saleOrder->ordered_at?->format('d M Y, H:i') ?? '—' }}</td>
                        <td class="py-2.5">
                            <x-tallui-badge :type="$statusBadge" size="sm">{{ $saleOrder->status?->label() ?? '—' }}</x-tallui-badge>
                        </td>
                        <td class="pr-4 py-2.5 text-right text-sm font-medium">{{ number_format((float) $saleOrder->total_amount, 2) }} BDT</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="py-8 text-center text-sm text-base-content/60">No sale history yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-tallui-card>

</div>
</div>

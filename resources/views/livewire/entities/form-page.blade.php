<div>
<x-tallui-notification />

<x-tallui-page-header
    :title="($recordId ? 'Edit ' : 'New ') . $definition['singular']"
    :subtitle="'Maintain ' . strtolower($definition['singular']) . ' details.'"
    icon="o-pencil-square"
>
    <x-slot:breadcrumbs>
        <x-tallui-breadcrumb :links="[
            ['label' => 'Inventory', 'href' => route('inventory.dashboard')],
            ['label' => $definition['label'], 'href' => route('inventory.entities.' . $entity . '.index')],
            ['label' => $recordId ? 'Edit' : 'New'],
        ]" />
    </x-slot:breadcrumbs>
    <x-slot:actions>
        <x-tallui-button
            :label="'Back to ' . $definition['label']"
            icon="o-arrow-left"
            :link="route('inventory.entities.' . $entity . '.index')"
            class="btn-ghost btn-sm"
        />
    </x-slot:actions>
</x-tallui-page-header>

<x-tallui-card
    :title="$definition['singular']"
    subtitle="Fill in the fields and save."
    icon="o-document-text"
    :shadow="true"
>
    <form wire:submit="save" enctype="multipart/form-data" class="space-y-5">
        @if ($supportsPrimaryImage)
            @php
                $pendingPrimaryImageUrl = null;

                if ($primaryImage) {
                    try {
                        $pendingPrimaryImageUrl = $primaryImage->temporaryUrl();
                    } catch (Throwable) {
                        $pendingPrimaryImageUrl = null;
                    }
                }
            @endphp

            <div class="rounded-xl border border-base-200 bg-base-50 p-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-[10rem_1fr] md:items-start">
                    <div class="overflow-hidden rounded-xl border border-base-200 bg-base-100">
                        @if ($pendingPrimaryImageUrl || $currentPrimaryImageUrl)
                            <img
                                src="{{ $pendingPrimaryImageUrl ?: $currentPrimaryImageUrl }}"
                                @if (!$pendingPrimaryImageUrl && $currentPrimaryImageSrcset) srcset="{{ $currentPrimaryImageSrcset }}" sizes="10rem" @endif
                                alt="{{ $definition['singular'] }} image"
                                class="h-40 w-full object-cover"
                            />
                        @else
                            <div class="flex h-40 w-full items-center justify-center text-base-content/30">
                                <x-tallui-icon name="o-photo" class="h-10 w-10" />
                            </div>
                        @endif
                    </div>

                    <div class="space-y-3">
                        <div>
                            <div class="text-sm font-semibold text-base-content">Primary Image</div>
                            <div class="mt-1 text-xs text-base-content/60">
                                Upload, replace, or remove the image used across inventory and storefront views.
                            </div>
                        </div>

                        <x-tallui-file-upload
                            name="primary_image"
                            wire:model="primaryImage"
                            accept="image/*"
                            :max-size-mb="4"
                            :preview="true"
                            upload-text="Drop image here or click to upload"
                            helper="Accepted image files up to 4MB."
                            :error="$errors->first('primaryImage')"
                        />

                        <div class="flex flex-wrap gap-2">
                            @if ($recordId && $primaryImage)
                                <x-tallui-button
                                    label="Upload Image"
                                    icon="o-arrow-up-tray"
                                    class="btn-primary btn-sm"
                                    type="button"
                                    wire:click="uploadPrimaryImage"
                                    :spinner="'uploadPrimaryImage'"
                                />
                            @endif

                            @if ($primaryImage)
                                <x-tallui-button
                                    label="Remove Selected"
                                    icon="o-x-mark"
                                    class="btn-ghost btn-sm"
                                    type="button"
                                    wire:click="removeSelectedImage"
                                />
                            @endif

                            @if ($recordId && $currentPrimaryImageUrl)
                                <x-tallui-button
                                    label="Delete Current Image"
                                    icon="o-trash"
                                    class="btn-ghost btn-sm text-error"
                                    type="button"
                                    wire:click="deletePrimaryImage"
                                    wire:confirm="Delete the current image?"
                                />
                            @endif
                        </div>

                        <div wire:loading wire:target="primaryImage" class="text-xs text-base-content/60">
                            Uploading selected image...
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach ($definition['form_fields'] as $field)
                <div class="{{ in_array($field['type'], ['textarea', 'text-editor', 'json'], true) ? 'md:col-span-2' : '' }}">
                    @if ($field['type'] === 'textarea')
                        <x-tallui-form-group :label="$field['label']" :error="$errors->first('form.' . $field['name'])">
                            <x-tallui-textarea
                                :name="$field['name']"
                                wire:model="form.{{ $field['name'] }}"
                                rows="3"
                            />
                        </x-tallui-form-group>

                    @elseif ($field['type'] === 'text-editor')
                        <x-tallui-form-group :label="$field['label']" :error="$errors->first('form.' . $field['name'])">
                            <x-tallui-text-editor
                                :name="$field['name']"
                                wire:model="form.{{ $field['name'] }}"
                                rows="5"
                                class="font-mono text-sm"
                            />
                        </x-tallui-form-group>

                    @elseif ($field['type'] === 'json')
                        <x-tallui-form-group :label="$field['label']" :error="$errors->first('form.' . $field['name'])">
                            <x-tallui-textarea
                                :name="$field['name']"
                                placeholder='{"key": "value"}'
                                wire:model="form.{{ $field['name'] }}"
                                rows="3"
                                class="font-mono text-sm"
                            />
                        </x-tallui-form-group>

                    @elseif ($field['type'] === 'select')
                        <x-tallui-form-group :label="$field['label']" :error="$errors->first('form.' . $field['name'])">
                            <x-tallui-select :name="$field['name']" wire:model="form.{{ $field['name'] }}">
                                <option value="">Select {{ strtolower($field['label']) }}…</option>
                                @foreach ($options[$field['name']] ?? [] as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </x-tallui-select>
                        </x-tallui-form-group>

                    @elseif ($field['type'] === 'checkbox')
                        <div class="flex items-center gap-3 pt-6">
                            <x-tallui-checkbox
                                :name="$field['name']"
                                :label="$field['label']"
                                wire:model="form.{{ $field['name'] }}"
                            />
                        </div>

                    @else
                        <x-tallui-form-group :label="$field['label']" :error="$errors->first('form.' . $field['name'])">
                            <x-tallui-input
                                :name="$field['name']"
                                :type="match($field['type']) {
                                    'number' => 'number',
                                    'date'   => 'date',
                                    'email'  => 'email',
                                    default  => 'text',
                                }"
                                :step="$field['type'] === 'number' ? '0.0001' : null"
                                wire:model="form.{{ $field['name'] }}"
                                :class="$errors->has('form.' . $field['name']) ? 'input-error' : ''"
                            />
                        </x-tallui-form-group>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="flex items-center justify-end gap-2 pt-2 border-t border-base-200">
            <x-tallui-button
                :label="'Back to ' . $definition['label']"
                icon="o-arrow-left"
                :link="route('inventory.entities.' . $entity . '.index')"
                class="btn-ghost"
            />
            <x-tallui-button
                :label="'Save ' . $definition['singular']"
                icon="o-check"
                class="btn-primary"
                type="submit"
                :spinner="'save'"
            />
        </div>
    </form>
</x-tallui-card>

@if ($entity === 'customers' && $recordId)
    <div id="history" class="mt-6 space-y-4">

        {{-- Credit + Analytics: credit is compact, analytics is rich and spans full width on small screens --}}
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">

            <div class="xl:col-span-1">
            <x-tallui-card
                title="Credit"
                subtitle="Current exposure and available headroom."
                icon="o-banknotes"
                :shadow="true"
            >
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
            </div>{{-- xl:col-span-1 --}}

            <div class="xl:col-span-2">
            <x-tallui-card
                title="Analytics"
                subtitle="Buying behaviour, CLV, RFM, and demand forecast."
                icon="o-presentation-chart-line"
                :shadow="true"
            >
                @php
                    $rfmLabel     = $customerAnalytics['rfm_label'] ?? 'Active';
                    $churnRisk    = $customerAnalytics['churn_risk'] ?? 'none';
                    $rfmColor     = match ($rfmLabel) {
                        'VIP'            => 'text-warning',
                        'Loyal'          => 'text-success',
                        'Cannot Lose'    => 'text-error',
                        'At Risk'        => 'text-warning',
                        'Lost'           => 'text-error',
                        'Promising'      => 'text-info',
                        'Potential Loyal'=> 'text-primary',
                        default          => '',
                    };
                    $churnColor   = match ($churnRisk) {
                        'high'   => 'text-error',
                        'medium' => 'text-warning',
                        'low'    => 'text-info',
                        default  => 'text-success',
                    };
                    $churnLabel   = match ($churnRisk) {
                        'high'   => 'High',
                        'medium' => 'Medium',
                        'low'    => 'Low',
                        default  => 'None',
                    };
                    $rfmR = $customerAnalytics['rfm_recency'] ?? 1;
                    $rfmF = $customerAnalytics['rfm_frequency'] ?? 1;
                    $rfmM = $customerAnalytics['rfm_monetary'] ?? 1;
                @endphp

                {{-- Key metrics row --}}
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

                {{-- RFM + Churn --}}
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

                {{-- Segmentation + Churn + Frequency --}}
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
                        <div class="font-medium">{{ $customerAnalytics['demographic'] ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-base-content/50">Zone</div>
                        <div class="font-medium">{{ $customerAnalytics['zone'] ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-base-content/50">Area</div>
                        <div class="font-medium">{{ $customerAnalytics['area'] ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-base-content/50">Distinct Products</div>
                        <div class="font-medium">{{ $customerAnalytics['distinct_products'] ?? 0 }}</div>
                    </div>
                </div>

                {{-- Monthly trend --}}
                @if (!empty($customerAnalytics['monthly_trend']))
                    <div class="mt-4">
                        <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-base-content/50">
                            Revenue Trend — last {{ $customerAnalytics['lookback_days'] ?? 180 }} days
                        </div>
                        @php
                            $trendMax = max(1, max(array_column($customerAnalytics['monthly_trend'], 'revenue')));
                        @endphp
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

                {{-- Top products --}}
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
            </div>{{-- xl:col-span-2 --}}

        </div>

        <x-tallui-card
            title="Order History"
            subtitle="Recent sale orders for this customer."
            icon="o-clock"
            :shadow="true"
        >
            {{-- Mobile: card stack --}}
            <div class="space-y-3 sm:hidden">
                @forelse ($customerHistory as $saleOrder)
                    @php
                        $soStatus = $saleOrder->status?->value ?? '';
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

            {{-- Tablet+: table --}}
            <div class="hidden overflow-x-auto sm:block">
                <table class="table table-sm w-full">
                    <thead>
                        <tr class="bg-base-50 text-xs uppercase text-base-content/50">
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
                                $soStatus = $saleOrder->status?->value ?? '';
                                $statusBadge = match ($soStatus) {
                                    'fulfilled'  => 'success',
                                    'cancelled'  => 'error',
                                    'partial'    => 'warning',
                                    'processing' => 'info',
                                    'confirmed'  => 'primary',
                                    default      => 'neutral',
                                };
                            @endphp
                            <tr class="hover:bg-base-50/50">
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
@endif
</div>

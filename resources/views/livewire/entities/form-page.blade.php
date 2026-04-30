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
        <x-tallui-card
            title="Customer Credit"
            subtitle="Current credit exposure and remaining headroom."
            icon="o-banknotes"
            :shadow="true"
        >
            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                <div class="rounded-2xl border border-base-200 bg-base-50 p-4">
                    <div class="text-xs uppercase text-base-content/50">Credit Limit</div>
                    <div class="mt-1 text-lg font-semibold">{{ number_format((float) ($customerCreditSnapshot['credit_limit_amount'] ?? 0), 2) }} BDT</div>
                </div>
                <div class="rounded-2xl border border-base-200 bg-base-50 p-4">
                    <div class="text-xs uppercase text-base-content/50">Open Exposure</div>
                    <div class="mt-1 text-lg font-semibold">{{ number_format((float) ($customerCreditSnapshot['outstanding_exposure'] ?? 0), 2) }} BDT</div>
                </div>
                <div class="rounded-2xl border border-base-200 bg-base-50 p-4">
                    <div class="text-xs uppercase text-base-content/50">Available Credit</div>
                    <div class="mt-1 text-lg font-semibold {{ (($customerCreditSnapshot['available_credit_amount'] ?? 0) < 0) ? 'text-error' : '' }}">
                        {{ number_format((float) ($customerCreditSnapshot['available_credit_amount'] ?? 0), 2) }} BDT
                    </div>
                </div>
            </div>
        </x-tallui-card>

        <x-tallui-card
            title="Customer Analytics"
            subtitle="Segment, buying history, and projected demand for this customer."
            icon="o-presentation-chart-line"
            :shadow="true"
        >
            <div class="grid grid-cols-1 gap-3 md:grid-cols-7">
                <div class="rounded-2xl border border-base-200 bg-base-50 p-4">
                    <div class="text-xs uppercase text-base-content/50">Segment</div>
                    <div class="mt-1 text-lg font-semibold">{{ $customerAnalytics['segment'] ?? 'New' }}</div>
                </div>
                <div class="rounded-2xl border border-base-200 bg-base-50 p-4">
                    <div class="text-xs uppercase text-base-content/50">Demography</div>
                    <div class="mt-1 text-lg font-semibold">{{ $customerAnalytics['demographic'] ?? 'Unassigned' }}</div>
                </div>
                <div class="rounded-2xl border border-base-200 bg-base-50 p-4">
                    <div class="text-xs uppercase text-base-content/50">Zone</div>
                    <div class="mt-1 text-lg font-semibold">{{ $customerAnalytics['zone'] ?? 'Unassigned' }}</div>
                </div>
                <div class="rounded-2xl border border-base-200 bg-base-50 p-4">
                    <div class="text-xs uppercase text-base-content/50">Area</div>
                    <div class="mt-1 text-lg font-semibold">{{ $customerAnalytics['area'] ?? 'Unassigned' }}</div>
                </div>
                <div class="rounded-2xl border border-base-200 bg-base-50 p-4">
                    <div class="text-xs uppercase text-base-content/50">Revenue History</div>
                    <div class="mt-1 text-lg font-semibold">{{ number_format((float) ($customerAnalytics['history_revenue'] ?? 0), 2) }} BDT</div>
                </div>
                <div class="rounded-2xl border border-base-200 bg-base-50 p-4">
                    <div class="text-xs uppercase text-base-content/50">Projected Revenue</div>
                    <div class="mt-1 text-lg font-semibold">{{ number_format((float) ($customerAnalytics['forecast_revenue'] ?? 0), 2) }} BDT</div>
                </div>
                <div class="rounded-2xl border border-base-200 bg-base-50 p-4">
                    <div class="text-xs uppercase text-base-content/50">Last Order</div>
                    <div class="mt-1 text-lg font-semibold">{{ $customerAnalytics['last_order_at'] ?? '—' }}</div>
                </div>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
                <div><span class="text-base-content/50">Orders</span><div class="font-semibold">{{ $customerAnalytics['orders_count'] ?? 0 }}</div></div>
                <div><span class="text-base-content/50">Products</span><div class="font-semibold">{{ $customerAnalytics['distinct_products'] ?? 0 }}</div></div>
                <div><span class="text-base-content/50">Avg Order</span><div class="font-semibold">{{ number_format((float) ($customerAnalytics['avg_order_value'] ?? 0), 2) }}</div></div>
                <div><span class="text-base-content/50">Projected Qty</span><div class="font-semibold">{{ number_format((float) ($customerAnalytics['forecast_qty'] ?? 0), 2) }}</div></div>
            </div>
        </x-tallui-card>

        <x-tallui-card
            title="Customer History"
            subtitle="Recent sale orders for quick review."
            icon="o-clock"
            :shadow="true"
        >
            <div class="overflow-x-auto">
                <table class="table table-sm w-full">
                    <thead>
                        <tr class="bg-base-50 text-xs text-base-content/50 uppercase">
                            <th class="pl-4">Order</th>
                            <th>Warehouse</th>
                            <th>Ordered At</th>
                            <th>Status</th>
                            <th class="pr-4 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-base-200">
                        @forelse ($customerHistory as $saleOrder)
                            <tr>
                                <td class="pl-4 py-2 text-sm font-medium">{{ $saleOrder->so_number }}</td>
                                <td class="py-2 text-sm">{{ $saleOrder->warehouse?->name ?? '—' }}</td>
                                <td class="py-2 text-sm">{{ $saleOrder->ordered_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="py-2 text-sm">{{ $saleOrder->status?->label() ?? '—' }}</td>
                                <td class="pr-4 py-2 text-right text-sm">{{ number_format((float) $saleOrder->total_amount, 2) }} BDT</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-6 text-center text-sm text-base-content/60">No sale history yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-tallui-card>
    </div>
@endif
</div>

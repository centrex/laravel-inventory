<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Enums\SaleOrderStatus;
use Centrex\Inventory\Models\{ProductPrice, SaleOrder};
use Centrex\ModelData\Models\Data;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\{Collection as EloquentCollection, Model};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\{Component, WithPagination};

#[Layout('layouts.app')]
class DispatchTerminalPage extends Component
{
    use WithPagination;

    private const PARCEL_STATUSES = [
        'Order confirmed',
        'Reserved for picking',
        'Packed',
        'Ready for courier',
        'Dispatched',
        'Out for delivery',
        'Delivered',
        'Delivery failed',
        'Returned',
        'Cancelled',
    ];

    public string $search = '';

    public string $status = 'open';

    public array $orderForms = [];

    public bool $modalOpen = false;

    public ?int $modalOrderId = null;

    public bool $detailModalOpen = false;

    public ?int $detailModalOrderId = null;

    public bool $detailShowPrices = false;

    public ?int $priceHistoryProductId = null;

    public int $priceHistoryDays = 365;

    public ?int $printOrderId = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'status' => ['except' => 'open'],
    ];

    public function updatingSearch(): void
    {
        $this->orderForms = [];
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->orderForms = [];
        $this->resetPage();
    }

    public function updatedPage(): void
    {
        $this->orderForms = [];
    }

    public function openModal(int $saleOrderId): void
    {
        if (!isset($this->orderForms[$saleOrderId])) {
            $saleOrder = SaleOrder::query()
                ->with('warehouse')
                ->where('document_type', 'order')
                ->findOrFail($saleOrderId);
            $meta = $this->metadataFor($saleOrder);
            $this->orderForms[$saleOrderId] = $this->formStateFor($saleOrder, $meta);
        }

        $this->modalOrderId = $saleOrderId;
        $this->modalOpen = true;
    }

    public function closeModal(): void
    {
        $this->modalOpen = false;
        $this->modalOrderId = null;
    }

    public function openDetailModal(int $saleOrderId): void
    {
        $this->detailModalOrderId = $saleOrderId;
        $this->detailModalOpen = true;
        $this->detailShowPrices = true;
    }

    public function openDetailModalView(int $saleOrderId): void
    {
        $this->detailModalOrderId = $saleOrderId;
        $this->detailModalOpen = true;
        $this->detailShowPrices = false;
    }

    public function closeDetailModal(): void
    {
        $this->detailModalOpen = false;
        $this->detailModalOrderId = null;
        $this->detailShowPrices = false;
        $this->priceHistoryProductId = null;
    }

    public function openPriceHistory(int $productId): void
    {
        $this->priceHistoryProductId = $productId;
    }

    public function closePriceHistory(): void
    {
        $this->priceHistoryProductId = null;
    }

    public function openPrintNote(int $saleOrderId): void
    {
        $this->printOrderId = $saleOrderId;
        $this->dispatch('print-dispatch-note');
    }

    public function closePrintNote(): void
    {
        $this->printOrderId = null;
    }

    public function updateOrder(int $saleOrderId): void
    {
        $saleOrder = SaleOrder::query()
            ->with('warehouse')
            ->where('document_type', 'order')
            ->findOrFail($saleOrderId);

        $form = $this->orderForms[$saleOrderId] ?? [];

        validator($form, [
            'carrier'         => ['nullable', 'string', 'max:80'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'parcel_status'   => ['required', 'string', Rule::in(self::PARCEL_STATUSES)],
            'eta'             => ['nullable', 'date'],
            'location'        => ['nullable', 'string', 'max:160'],
            'dispatch_note'   => ['nullable', 'string', 'max:1000'],
            'order_status'    => ['required', Rule::in(array_column(SaleOrderStatus::cases(), 'value'))],
        ])->validate();

        $metadata = $this->metadataFor($saleOrder);

        $updatedMetadata = array_merge($metadata, [
            'carrier'             => filled($form['carrier'] ?? null) ? $form['carrier'] : ($metadata['carrier'] ?? 'Connect Courier'),
            'tracking_number'     => filled($form['tracking_number'] ?? null) ? $form['tracking_number'] : ($metadata['tracking_number'] ?? $this->trackingNumberFor($saleOrder)),
            'parcel_status'       => $form['parcel_status'],
            'eta'                 => filled($form['eta'] ?? null) ? Carbon::parse($form['eta'])->format('d M Y') : ($metadata['eta'] ?? null),
            'location'            => filled($form['location'] ?? null) ? $form['location'] : ($saleOrder->warehouse?->name ?? $metadata['location'] ?? null),
            'dispatch_note'       => $form['dispatch_note'] ?? null,
            'dispatched_by'       => auth()->user()?->name,
            'dispatched_by_id'    => auth()->user()?->getKey(),
            'dispatch_updated_at' => now()->toDateTimeString(),
        ]);

        $this->putMetadata($saleOrder, $updatedMetadata);

        if ($saleOrder->status?->value !== $form['order_status']) {
            $saleOrder->forceFill(['status' => $form['order_status']])->save();
        }

        $this->orderForms[$saleOrderId] = $this->formStateFor($saleOrder->fresh('warehouse'), $updatedMetadata);

        $this->closeModal();

        session()->flash('status', "{$saleOrder->so_number} dispatch updated.");
    }

    public function quickDispatch(int $saleOrderId, string $action): void
    {
        $saleOrder = SaleOrder::query()
            ->with('warehouse')
            ->where('document_type', 'order')
            ->findOrFail($saleOrderId);

        $meta = $this->metadataFor($saleOrder);

        [$parcelStatus, $orderStatus] = match ($action) {
            'out_for_delivery' => ['Out for delivery', SaleOrderStatus::SHIPPED->value],
            'delivered'        => ['Delivered', SaleOrderStatus::FULFILLED->value],
            default            => ['Dispatched', SaleOrderStatus::SHIPPED->value],
        };

        $updatedMeta = array_merge($meta, [
            'carrier'             => $meta['carrier'] ?? 'Connect Courier',
            'tracking_number'     => $meta['tracking_number'] ?? $this->trackingNumberFor($saleOrder),
            'parcel_status'       => $parcelStatus,
            'location'            => $meta['location'] ?? $saleOrder->warehouse?->name,
            'dispatched_by'       => auth()->user()?->name,
            'dispatched_by_id'    => auth()->user()?->getKey(),
            'dispatch_updated_at' => now()->toDateTimeString(),
        ]);

        $this->putMetadata($saleOrder, $updatedMeta);
        $saleOrder->forceFill(['status' => $orderStatus])->save();
        $this->orderForms[$saleOrderId] = $this->formStateFor($saleOrder->fresh('warehouse'), $updatedMeta);

        session()->flash('status', "{$saleOrder->so_number} marked as {$parcelStatus}.");
    }

    public function render(): View
    {
        $query = SaleOrder::query()
            ->with(['customer', 'warehouse', 'items.product'])
            ->where('document_type', 'order')
            ->latest('ordered_at')
            ->latest('id');

        match ($this->status) {
            'confirmed', 'processing', 'partial', 'shipped', 'fulfilled', 'completed', 'cancelled', 'returned' => $query->where('status', $this->status),
            'all'                                                                                              => null,
            default                                                                                            => $query->whereIn('status', ['confirmed', 'processing', 'partial', 'shipped']),
        };

        $search = trim($this->search);

        if ($search !== '') {
            $query->where(function ($query) use ($search): void {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';

                $query->where('so_number', 'like', $like)
                    ->orWhereHas('customer', function ($query) use ($like): void {
                        $query->where('name', 'like', $like)
                            ->orWhere('organization_name', 'like', $like)
                            ->orWhere('code', 'like', $like)
                            ->orWhere('phone', 'like', $like);
                    });
            });
        }

        $orders = $query->paginate(20);
        $metadata = $this->metadataForOrders($orders->getCollection());

        $this->seedOrderForms($orders->getCollection(), $metadata);

        $modalOrder = null;
        $modalMeta = [];

        if ($this->modalOpen && $this->modalOrderId) {
            $modalOrder = $orders->getCollection()->find($this->modalOrderId)
                ?? SaleOrder::query()->with(['customer', 'warehouse'])->find($this->modalOrderId);
            $modalMeta = $metadata[$this->modalOrderId] ?? ($modalOrder ? $this->metadataFor($modalOrder) : []);
        }

        $detailOrder = null;
        $detailMeta = [];

        if ($this->detailModalOpen && $this->detailModalOrderId) {
            $detailOrder = SaleOrder::query()
                ->with(['customer', 'warehouse', 'items.product', 'items.variant'])
                ->find($this->detailModalOrderId);
            $detailMeta = $metadata[$this->detailModalOrderId] ?? ($detailOrder ? $this->metadataFor($detailOrder) : []);
        }

        $detailPriceHistory = collect();
        $detailProductNames = [];
        $detailChartData = [];

        if ($this->detailModalOpen && $this->detailModalOrderId && $detailOrder && $this->detailShowPrices) {
            $productIds = $detailOrder->items->pluck('product_id')->filter()->unique()->values();

            if ($productIds->isNotEmpty()) {
                $priceQuery = ProductPrice::query()
                    ->with(['warehouse', 'variant'])
                    ->whereIn('product_id', $productIds)
                    ->orderByDesc('effective_from')
                    ->orderByDesc('created_at');

                if ($this->priceHistoryDays > 0) {
                    $cutoff = now()->subDays($this->priceHistoryDays);
                    $priceQuery->where(function ($q) use ($cutoff): void {
                        $q->where(function ($q2) use ($cutoff): void {
                            $q2->whereNull('effective_from')->where('created_at', '>=', $cutoff);
                        })->orWhere('effective_from', '>=', $cutoff);
                    });
                }

                $detailPriceHistory = $priceQuery->get()->groupBy('product_id');
            }

            foreach ($detailOrder->items as $item) {
                if ($item->product_id && !array_key_exists($item->product_id, $detailProductNames)) {
                    $detailProductNames[$item->product_id] = $item->product?->name ?? "Product #{$item->product_id}";
                }
            }

            foreach ($detailPriceHistory as $pId => $prices) {
                // Use effective_from when set, fall back to created_at for the date axis
                $dateOf = static fn (ProductPrice $p): string => (
                    $p->effective_from ?? $p->created_at
                )?->format('d M Y') ?? now()->format('d M Y');

                // Collect all unique dates across every tier, sorted ascending
                $allDates = $prices
                    ->sortBy(static fn (ProductPrice $p) => ($p->effective_from ?? $p->created_at)?->timestamp ?? 0)
                    ->map($dateOf)
                    ->unique()
                    ->values()
                    ->toArray();

                if ($allDates === []) {
                    continue;
                }

                $series = [];

                foreach ($prices->groupBy('price_tier_code') as $tierCode => $tierPrices) {
                    $first = $tierPrices->first();
                    $label = ($first->price_tier_name ?? $tierCode) . ($first->is_damaged ? ' (damaged)' : '');

                    // Map date string → price for this tier (sorted chronologically)
                    $priceByDate = $tierPrices
                        ->sortBy(static fn (ProductPrice $p) => ($p->effective_from ?? $p->created_at)?->timestamp ?? 0)
                        ->mapWithKeys(fn (ProductPrice $p): array => [
                            $dateOf($p) => (float) $p->price_local,
                        ])
                        ->toArray();

                    // Align to the shared date axis, carrying the last known price forward (step behaviour)
                    $aligned = [];
                    $last = null;

                    foreach ($allDates as $date) {
                        if (isset($priceByDate[$date])) {
                            $last = $priceByDate[$date];
                        }

                        $aligned[] = $last ?? 0;
                    }

                    $series[] = ['name' => $label, 'data' => $aligned];
                }

                if ($series !== []) {
                    $detailChartData[(int) $pId] = [
                        'series'     => $series,
                        'categories' => $allDates,
                    ];
                }
            }
        }

        $printOrder = null;
        $printMeta = [];

        if ($this->printOrderId) {
            $printOrder = SaleOrder::query()
                ->with(['customer', 'warehouse', 'items.product', 'items.variant'])
                ->find($this->printOrderId);
            $printMeta = $printOrder ? $this->metadataFor($printOrder) : [];
        }

        return view('inventory::livewire.transactions.dispatch-terminal', [
            'orders'               => $orders,
            'metadata'             => $metadata,
            'statusOptions'        => $this->statusOptions(),
            'orderStatusOptions'   => $this->orderStatusOptions(),
            'parcelStatuses'       => self::PARCEL_STATUSES,
            'summary'              => $this->summary(),
            'modelDataReady'       => $this->modelDataReady(),
            'modalOrder'           => $modalOrder,
            'modalMeta'            => $modalMeta,
            'detailOrder'          => $detailOrder,
            'detailMeta'           => $detailMeta,
            'detailPriceHistory'   => $detailPriceHistory,
            'detailProductNames'   => $detailProductNames,
            'detailChartData'      => $detailChartData,
            'printOrder'           => $printOrder,
            'printMeta'            => $printMeta,
            'canViewDispatcherTab' => auth()->user()?->can('inventory.dispatch.dispatcher-tab') ?? false,
            'canViewUpdaterTab'    => auth()->user()?->can('inventory.dispatch.updater-tab') ?? false,
        ]);
    }

    private function seedOrderForms(EloquentCollection $orders, array $metadata): void
    {
        foreach ($orders as $order) {
            if (isset($this->orderForms[$order->getKey()])) {
                continue;
            }

            $this->orderForms[$order->getKey()] = $this->formStateFor($order, $metadata[$order->getKey()] ?? []);
        }
    }

    private function formStateFor(SaleOrder $saleOrder, array $metadata): array
    {
        return [
            'tracking_number' => $metadata['tracking_number'] ?? '',
            'carrier'         => $metadata['carrier'] ?? 'Connect Courier',
            'parcel_status'   => $metadata['parcel_status'] ?? 'Order confirmed',
            'order_status'    => $saleOrder->status?->value ?? SaleOrderStatus::CONFIRMED->value,
            'eta'             => $this->etaDate($metadata['eta'] ?? null),
            'location'        => $metadata['location'] ?? $saleOrder->warehouse?->name,
            'dispatch_note'   => $metadata['dispatch_note'] ?? '',
        ];
    }

    private function metadataForOrders(EloquentCollection $orders): array
    {
        if (!$this->modelDataReady() || $orders->isEmpty()) {
            return [];
        }

        $model = $orders->first();
        $modelType = $model->getMorphClass();

        return Data::query()
            ->where('model_type', $modelType)
            ->whereIn('model_id', $orders->modelKeys())
            ->get()
            ->mapWithKeys(static fn (Data $record): array => [
                (int) $record->model_id => is_array($record->data)
                    ? $record->data
                    : (json_decode((string) $record->data, true) ?: []),
            ])
            ->all();
    }

    private function metadataFor(Model $model): array
    {
        if (!$this->modelDataReady()) {
            return [];
        }

        $record = Data::query()
            ->where('model_type', $model->getMorphClass())
            ->where('model_id', $model->getKey())
            ->first();

        if (!$record) {
            return [];
        }

        return is_array($record->data)
            ? $record->data
            : (json_decode((string) $record->data, true) ?: []);
    }

    private function putMetadata(Model $model, array $metadata): void
    {
        if (!$this->modelDataReady()) {
            return;
        }

        $modelType = $model->getMorphClass();
        $modelId = $model->getKey();
        $dataType = 'data';
        $key = Data::generateKey($modelType, $modelId, $dataType);

        Data::query()->updateOrCreate(
            ['key' => $key],
            [
                'key'        => $key,
                'data_type'  => $dataType,
                'model_type' => $modelType,
                'model_id'   => $modelId,
                'data'       => $metadata,
            ],
        );
    }

    private function trackingNumberFor(SaleOrder $saleOrder): string
    {
        return 'CTRX-' . now()->format('ym') . str_pad((string) $saleOrder->getKey(), 6, '0', STR_PAD_LEFT);
    }

    private function etaDate(?string $eta): ?string
    {
        if (!filled($eta)) {
            return null;
        }

        try {
            return Carbon::parse($eta)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function summary(): array
    {
        return [
            'confirmed'  => SaleOrder::query()->where('document_type', 'order')->where('status', 'confirmed')->count(),
            'processing' => SaleOrder::query()->where('document_type', 'order')->where('status', 'processing')->count(),
            'partial'    => SaleOrder::query()->where('document_type', 'order')->where('status', 'partial')->count(),
            'shipped'    => SaleOrder::query()->where('document_type', 'order')->where('status', 'shipped')->count(),
        ];
    }

    private function statusOptions(): array
    {
        return [
            'open' => 'Open queue',
            'all'  => 'All orders',
            ...$this->orderStatusOptions(),
        ];
    }

    private function orderStatusOptions(): array
    {
        return collect(SaleOrderStatus::cases())
            ->mapWithKeys(static fn (SaleOrderStatus $status): array => [$status->value => $status->label()])
            ->all();
    }

    private function modelDataReady(): bool
    {
        return class_exists(Data::class) && Schema::hasTable('model_datas');
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Controllers\Web;

use Centrex\Inventory\Enums\SaleOrderStatus;
use Centrex\Inventory\Models\SaleOrder;
use Centrex\ModelData\Models\Data;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\{Collection as EloquentCollection, Model};
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

final class DispatchTerminalController extends Controller
{
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

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', 'open'));

        $query = SaleOrder::query()
            ->with(['customer', 'warehouse', 'items.product'])
            ->where('document_type', 'order')
            ->latest('ordered_at')
            ->latest('id');

        match ($status) {
            'confirmed', 'processing', 'partial', 'shipped', 'fulfilled', 'completed', 'cancelled' => $query->where('status', $status),
            'all'                                                                                  => null,
            default                                                                                => $query->whereIn('status', ['confirmed', 'processing', 'partial', 'shipped']),
        };

        if ($search !== '') {
            $query->where(static function ($query) use ($search): void {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';

                $query->where('so_number', 'like', $like)
                    ->orWhereHas('customer', static function ($query) use ($like): void {
                        $query->where('name', 'like', $like)
                            ->orWhere('organization_name', 'like', $like)
                            ->orWhere('code', 'like', $like)
                            ->orWhere('phone', 'like', $like);
                    });
            });
        }

        $orders = $query->paginate(20)->withQueryString();
        $metadata = $this->metadataForOrders($orders->getCollection());

        return view('inventory::dispatch.terminal', [
            'orders'         => $orders,
            'metadata'       => $metadata,
            'search'         => $search,
            'status'         => $status,
            'statusOptions'  => $this->statusOptions(),
            'parcelStatuses' => self::PARCEL_STATUSES,
            'summary'        => $this->summary(),
            'modelDataReady' => $this->modelDataReady(),
        ]);
    }

    public function update(Request $request, SaleOrder $saleOrder): RedirectResponse
    {
        $validated = $request->validate([
            'carrier'         => ['nullable', 'string', 'max:80'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'parcel_status'   => ['required', 'string', Rule::in(self::PARCEL_STATUSES)],
            'eta'             => ['nullable', 'date'],
            'location'        => ['nullable', 'string', 'max:160'],
            'dispatch_note'   => ['nullable', 'string', 'max:1000'],
            'order_status'    => ['required', Rule::in(array_column(SaleOrderStatus::cases(), 'value'))],
        ]);

        $metadata = $this->metadataFor($saleOrder);

        $this->putMetadata($saleOrder, array_merge($metadata, [
            'carrier'             => $validated['carrier'] ?: ($metadata['carrier'] ?? 'Connect Courier'),
            'tracking_number'     => $validated['tracking_number'] ?: ($metadata['tracking_number'] ?? $this->trackingNumberFor($saleOrder)),
            'parcel_status'       => $validated['parcel_status'],
            'eta'                 => !empty($validated['eta']) ? Carbon::parse($validated['eta'])->format('d M Y') : ($metadata['eta'] ?? null),
            'location'            => $validated['location'] ?: ($saleOrder->warehouse?->name ?? $metadata['location'] ?? null),
            'dispatch_note'       => $validated['dispatch_note'] ?? null,
            'dispatched_by'       => $request->user()?->name,
            'dispatched_by_id'    => $request->user()?->getKey(),
            'dispatch_updated_at' => now()->toDateTimeString(),
        ]));

        if ($saleOrder->status?->value !== $validated['order_status']) {
            $saleOrder->forceFill(['status' => $validated['order_status']])->save();
        }

        return back()->with('status', "{$saleOrder->so_number} dispatch updated.");
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
            ...collect(SaleOrderStatus::cases())
                ->mapWithKeys(static fn (SaleOrderStatus $status): array => [$status->value => $status->label()])
                ->all(),
        ];
    }

    private function modelDataReady(): bool
    {
        return class_exists(Data::class) && Schema::hasTable('model_datas');
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Enums\SaleOrderStatus;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{ProductPrice, SaleOrder};
use Centrex\Inventory\Support\{CommercialTeamAccess, CourierIntegration};
use Centrex\ModelData\Models\Data;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\{Collection as EloquentCollection, Model};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Gate, Schema, Validator};
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

    /**
     * A sale order can only be dispatched once its stock has actually been reserved
     * (SaleOrder::reserveStock() moves it from CONFIRMED to PROCESSING). SHIPPED/FULFILLED are
     * included because quickDispatch() force-sets those statuses itself between the three quick
     * actions (dispatched → out_for_delivery → delivered), so a later action must still see the
     * order as dispatchable after an earlier one already advanced it.
     */
    private const DISPATCHABLE_STATUSES = ['processing', 'partial', 'shipped', 'fulfilled'];

    /** Parcel-status labels that don't yet imply stock has left the warehouse — safe from any order status. */
    private const PRE_RESERVATION_PARCEL_STATUSES = ['', 'Order confirmed'];

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

    public bool $parcelModalOpen = false;

    public ?int $parcelOrderId = null;

    /** Create-parcel modal fields: provider (pathao|redx|hand_carry), environment + reviewed parcel details. */
    public array $parcelForm = [];

    /** Redx delivery areas for the selected environment, lazy-loaded when Redx is picked. */
    public array $redxAreas = [];

    /** Redx merchant pickup stores (each carries its area) — the pickup-area choices. */
    public array $redxPickupStores = [];

    /** Filters the (large) delivery-area list in the parcel modal. */
    public string $redxAreaSearch = '';

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

    /** Prices are only shown to users with sale-updater access, regardless of who opened the modal. */
    public function openDetailModal(int $saleOrderId): void
    {
        $this->detailModalOrderId = $saleOrderId;
        $this->detailModalOpen = true;
        $this->detailShowPrices = $this->canViewUpdaterTab();
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

        Validator::make($form, [
            'carrier'         => ['nullable', 'string', 'max:80'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'parcel_status'   => ['required', 'string', Rule::in(self::PARCEL_STATUSES)],
            'eta'             => ['nullable', 'date'],
            'location'        => ['nullable', 'string', 'max:160'],
            'dispatch_note'   => ['nullable', 'string', 'max:1000'],
            'order_status'    => ['required', Rule::in(array_column(SaleOrderStatus::cases(), 'value'))],
        ])->after(function ($validator) use ($saleOrder, $form): void {
            $advancesPastReservation = !in_array($form['parcel_status'] ?? '', self::PRE_RESERVATION_PARCEL_STATUSES, true);

            if ($advancesPastReservation && !in_array($saleOrder->status?->value, self::DISPATCHABLE_STATUSES, true)) {
                $validator->errors()->add(
                    'parcel_status',
                    "{$saleOrder->so_number} is still {$saleOrder->status?->label()} — reserve its stock (move it to Processing) before it can be dispatched.",
                );
            }
        })->validate();

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

        if (!in_array($saleOrder->status?->value, self::DISPATCHABLE_STATUSES, true)) {
            session()->flash('dispatch_error', "{$saleOrder->so_number} is still {$saleOrder->status?->label()} — reserve its stock (move it to Processing) before it can be dispatched.");

            return;
        }

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

    /** Sale Updater tab: progress an order through Draft → Confirmed → Reserved → Shipped via the real workflow. */
    public function confirmSaleOrderFlow(int $saleOrderId): void
    {
        CommercialTeamAccess::authorizeAny(['sales.orders.manage', 'inventory.sale-orders.confirm']);

        try {
            $saleOrder = app(Inventory::class)->confirmSaleOrder($saleOrderId);
            session()->flash('status', "{$saleOrder->so_number} confirmed.");
        } catch (\Throwable $exception) {
            session()->flash('dispatch_error', $exception->getMessage());
        }
    }

    public function reserveSaleOrderFlow(int $saleOrderId): void
    {
        CommercialTeamAccess::authorizeAny(['sales.orders.manage', 'inventory.sale-orders.reserve']);

        try {
            $saleOrder = app(Inventory::class)->reserveStock($saleOrderId);

            if (!empty($saleOrder->shortageWarnings)) {
                session()->flash('dispatch_error', "Reserved {$saleOrder->so_number} with stock shortage — " . implode('; ', $saleOrder->shortageWarnings) . '. Post a GRN to cover before fulfillment.');
            } else {
                session()->flash('status', "Stock reserved for {$saleOrder->so_number}.");
            }
        } catch (\Throwable $exception) {
            session()->flash('dispatch_error', $exception->getMessage());
        }
    }

    /**
     * "Ship" — the plain Inventory::fulfillSaleOrder() call (decrements stock, posts
     * COGS). The parcel is booked in a separate, earlier step (see openParcelModal/
     * createParcelForOrder): the primary action offers Create Parcel first and only
     * switches to Ship once a tracking number exists (or the viewer can't book parcels).
     */
    public function fulfillSaleOrderFlow(int $saleOrderId): void
    {
        CommercialTeamAccess::authorizeAny(['sales.orders.manage', 'inventory.sale-orders.fulfill']);

        try {
            $saleOrder = app(Inventory::class)->fulfillSaleOrder($saleOrderId);
            session()->flash('status', "{$saleOrder->so_number} shipped.");
        } catch (\Throwable $exception) {
            session()->flash('dispatch_error', $exception->getMessage());
        }
    }

    public function openParcelModal(int $saleOrderId): void
    {
        Gate::authorize('inventory.courier.create-parcel');

        $saleOrder = SaleOrder::query()
            ->with(['customer', 'warehouse', 'items'])
            ->where('document_type', 'order')
            ->findOrFail($saleOrderId);

        $meta = $this->metadataFor($saleOrder);

        $weight = app(Inventory::class)->estimateShipping(
            $saleOrder->items->map(fn ($item): array => ['product_id' => $item->product_id, 'qty' => $item->qty_ordered])->all(),
        )['total_weight_kg'] ?? 0.0;

        $courierApiEnabled = app(CourierIntegration::class)->enabled();
        $saved = $this->savedShippingAddressFor($saleOrder);

        $this->parcelForm = [
            'provider'          => $courierApiEnabled ? (string) config('inventory.courier.default_provider', 'redx') : 'hand_carry',
            'environment'       => (string) config('inventory.courier.default_environment', 'sandbox'),
            'recipient_name'    => $saleOrder->customer?->organization_name ?? $saleOrder->customer?->name ?? 'Walk-in customer',
            'recipient_phone'   => $saleOrder->customer?->phone ?? $saved['phone'] ?? data_get($meta, 'shipping_address.phone', ''),
            'recipient_address' => $saved['address'] ?? data_get($meta, 'shipping_address.formatted', ''),
            'weight_kg'         => $weight > 0 ? (string) $weight : '0.5',
            'cod_amount'        => (string) round((float) $saleOrder->total_local, 2),
            'item_description'  => $saleOrder->so_number,
            'delivery_area_id'  => (string) (data_get($meta, 'shipping_address.delivery_area_id') ?? ''),
            'pickup_area_id'    => (string) config('inventory.courier.redx.pickup_area_id', ''),
            'carried_by'        => '',
        ];

        $this->redxAreaSearch = '';
        $this->parcelOrderId = $saleOrderId;
        $this->parcelModalOpen = true;

        $this->loadRedxOptions();
    }

    public function closeParcelModal(): void
    {
        $this->parcelModalOpen = false;
        $this->parcelOrderId = null;
    }

    public function updatedParcelFormProvider(): void
    {
        $this->loadRedxOptions();
    }

    public function updatedParcelFormEnvironment(): void
    {
        // Sandbox and live have separate area/pickup-store data sets.
        $this->redxAreas = [];
        $this->redxPickupStores = [];
        $this->loadRedxOptions();
    }

    /**
     * Lazy-load the Redx area + pickup-store lists once Redx is the selected provider.
     * A lookup failure degrades gracefully: the modal falls back to plain numeric id
     * inputs, so booking is still possible with ids from Redx's own panel.
     */
    private function loadRedxOptions(): void
    {
        if (($this->parcelForm['provider'] ?? '') !== 'redx' || $this->redxAreas !== []) {
            return;
        }

        $environment = (string) ($this->parcelForm['environment'] ?? 'sandbox');

        try {
            $courier = app(CourierIntegration::class);
            $this->redxAreas = $courier->redxAreas($environment);
            $this->redxPickupStores = $courier->redxPickupStores($environment);
        } catch (\Throwable $exception) {
            $this->redxAreas = [];
            $this->redxPickupStores = [];
            session()->flash('dispatch_error', "Could not load Redx areas: {$exception->getMessage()}");
        }
    }

    /** Delivery-area options narrowed by the search box — capped so the select stays usable. */
    private function filteredRedxAreas(): array
    {
        $search = mb_strtolower(trim($this->redxAreaSearch));
        $selectedId = (string) ($this->parcelForm['delivery_area_id'] ?? '');

        $areas = collect($this->redxAreas)
            ->filter(function (array $area) use ($search, $selectedId): bool {
                if ((string) ($area['id'] ?? '') === $selectedId) {
                    return true; // never filter away the current selection
                }

                if ($search === '') {
                    return true;
                }

                return str_contains(mb_strtolower((string) ($area['name'] ?? '')), $search)
                    || str_contains(mb_strtolower((string) ($area['district_name'] ?? '')), $search)
                    || str_contains((string) ($area['post_code'] ?? ''), $search);
            });

        return $areas->take(50)->values()->all();
    }

    /**
     * Create the parcel as its own step before shipping: books with the courier API
     * (Pathao/Redx) or records a hand-carry with an internally generated tracking
     * number. Stock is untouched — fulfilment stays a separate Ship click afterwards.
     */
    public function createParcelForOrder(): void
    {
        Gate::authorize('inventory.courier.create-parcel');

        if (!$this->parcelOrderId) {
            return;
        }

        $provider = (string) ($this->parcelForm['provider'] ?? '');
        $isHandCarry = $provider === 'hand_carry';

        Validator::make($this->parcelForm, [
            'provider'          => ['required', Rule::in(['pathao', 'redx', 'hand_carry'])],
            'environment'       => [Rule::excludeIf($isHandCarry), 'required', Rule::in(['sandbox', 'live'])],
            'recipient_name'    => ['required', 'string', 'max:160'],
            'recipient_phone'   => ['required', 'string', 'max:32'],
            'recipient_address' => [Rule::excludeIf($isHandCarry), 'required', 'string', 'max:500'],
            'weight_kg'         => [Rule::excludeIf($isHandCarry), 'required', 'numeric', 'min:0.01'],
            'cod_amount'        => [Rule::excludeIf($isHandCarry), 'required', 'numeric', 'min:0'],
            'item_description'  => ['nullable', 'string', 'max:160'],
            'delivery_area_id'  => ['required_if:provider,redx', 'nullable', 'integer', 'min:1'],
            'pickup_area_id'    => ['required_if:provider,redx', 'nullable', 'integer', 'min:1'],
            'carried_by'        => ['required_if:provider,hand_carry', 'nullable', 'string', 'max:160'],
        ], [
            'delivery_area_id.required_if' => 'Redx needs a delivery area.',
            'pickup_area_id.required_if'   => 'Redx needs a pickup area.',
            'carried_by.required_if'       => 'Who is hand-carrying this parcel?',
        ])->validate();

        $saleOrder = SaleOrder::query()
            ->with(['customer', 'warehouse', 'items'])
            ->where('document_type', 'order')
            ->findOrFail($this->parcelOrderId);

        $meta = $this->metadataFor($saleOrder);

        if (filled($meta['tracking_number'] ?? null)) {
            session()->flash('dispatch_error', "{$saleOrder->so_number} already has parcel {$meta['tracking_number']}.");
            $this->closeParcelModal();

            return;
        }

        if ($isHandCarry) {
            $trackingNumber = $this->trackingNumberFor($saleOrder);
            $carrierLabel = 'Hand Carry';
        } else {
            try {
                $result = app(CourierIntegration::class)->createParcel($saleOrder, $provider, (string) $this->parcelForm['environment'], [
                    'recipient_name'    => (string) $this->parcelForm['recipient_name'],
                    'recipient_phone'   => (string) $this->parcelForm['recipient_phone'],
                    'recipient_address' => (string) $this->parcelForm['recipient_address'],
                    'weight_kg'         => (float) $this->parcelForm['weight_kg'],
                    'cod_amount'        => (float) $this->parcelForm['cod_amount'],
                    'item_description'  => filled($this->parcelForm['item_description'] ?? null) ? $this->parcelForm['item_description'] : $saleOrder->so_number,
                    'delivery_area_id'  => filled($this->parcelForm['delivery_area_id'] ?? null) ? (int) $this->parcelForm['delivery_area_id'] : null,
                    'pickup_area_id'    => filled($this->parcelForm['pickup_area_id'] ?? null) ? (int) $this->parcelForm['pickup_area_id'] : null,
                ]);
            } catch (\Throwable $exception) {
                session()->flash('dispatch_error', "Courier parcel creation failed for {$saleOrder->so_number}: {$exception->getMessage()}");

                return;
            }

            $trackingNumber = $result['tracking_number'];
            $carrierLabel = ucfirst($provider);
        }

        $this->putMetadata($saleOrder, array_merge($meta, [
            'carrier'             => $carrierLabel,
            'courier_provider'    => $provider,
            'courier_environment' => $isHandCarry ? null : $this->parcelForm['environment'],
            'delivery_area_id'    => $provider === 'redx' ? (int) $this->parcelForm['delivery_area_id'] : null,
            'pickup_area_id'      => $provider === 'redx' ? (int) $this->parcelForm['pickup_area_id'] : null,
            'carried_by'          => $isHandCarry ? $this->parcelForm['carried_by'] : null,
            'tracking_number'     => $trackingNumber,
            'parcel_status'       => 'Ready for courier',
            'location'            => $meta['location'] ?? $saleOrder->warehouse?->name,
            'dispatched_by'       => auth()->user()?->name,
            'dispatched_by_id'    => auth()->user()?->getKey(),
            'dispatch_updated_at' => now()->toDateTimeString(),
        ]));

        $this->orderForms[$saleOrder->getKey()] = $this->formStateFor($saleOrder, $this->metadataFor($saleOrder));
        $this->closeParcelModal();

        session()->flash('status', "{$carrierLabel} parcel {$trackingNumber} created for {$saleOrder->so_number}.");
    }

    private function canCreateParcel(): bool
    {
        return Gate::allows('inventory.courier.create-parcel');
    }

    /**
     * The customer's saved address (centrex/laravel-addresses, optional peer package):
     * shipping-flagged first, then primary, then the newest one. Takes priority over any
     * ad-hoc shipping_address stored in dispatch metadata when prefilling the parcel form.
     *
     * @return array{address: ?string, phone: ?string}
     */
    private function savedShippingAddressFor(SaleOrder $saleOrder): array
    {
        $customer = $saleOrder->customer;
        $addressModel = (string) config('laravel-addresses.addresses.model', 'Centrex\\Addresses\\Models\\Address');

        if (!$customer || !class_exists($addressModel)) {
            return ['address' => null, 'phone' => null];
        }

        $addresses = $customer->addresses()->get();

        $address = $addresses->firstWhere('is_shipping', true)
            ?? $addresses->firstWhere('is_primary', true)
            ?? $addresses->sortByDesc('id')->first();

        if (!$address) {
            return ['address' => null, 'phone' => null];
        }

        return [
            'address' => method_exists($address, 'getLine') ? ($address->getLine() ?: null) : null,
            'phone'   => $address->contact_phone ?? null,
        ];
    }

    public function cancelSaleOrderFlow(int $saleOrderId): void
    {
        CommercialTeamAccess::authorizeAny(['sales.orders.manage', 'inventory.sale-orders.cancel']);

        try {
            $saleOrder = app(Inventory::class)->cancelSaleOrder($saleOrderId);
            session()->flash('status', "{$saleOrder->so_number} cancelled.");
        } catch (\Throwable $exception) {
            session()->flash('dispatch_error', $exception->getMessage());
        }
    }

    /**
     * Draft -> Confirmed -> Reserved -> Shipped step data, plus status-readiness and
     * permission checked separately so the view can tell "not your turn yet" apart from
     * "you don't have permission" (see primaryActionFor()).
     */
    private function saleFlowFor(SaleOrder $order): array
    {
        $status = $order->status;

        $statusReady = [
            'confirm' => $status === SaleOrderStatus::DRAFT,
            'reserve' => $status === SaleOrderStatus::CONFIRMED,
            'fulfill' => in_array($status, [SaleOrderStatus::PROCESSING, SaleOrderStatus::PARTIAL], true),
            'cancel'  => in_array($status, [SaleOrderStatus::DRAFT, SaleOrderStatus::CONFIRMED, SaleOrderStatus::PROCESSING, SaleOrderStatus::PARTIAL], true),
        ];

        $permitted = [
            'confirm' => Gate::any(['sales.orders.manage', 'inventory.sale-orders.confirm']),
            'reserve' => Gate::any(['sales.orders.manage', 'inventory.sale-orders.reserve']),
            'fulfill' => Gate::any(['sales.orders.manage', 'inventory.sale-orders.fulfill']),
            'cancel'  => Gate::any(['sales.orders.manage', 'inventory.sale-orders.cancel']),
        ];

        return [
            'steps'   => [['label' => 'Draft'], ['label' => 'Confirmed'], ['label' => 'Reserved'], ['label' => 'Shipped']],
            'current' => match ($status) {
                SaleOrderStatus::CONFIRMED => 2,
                SaleOrderStatus::PROCESSING, SaleOrderStatus::PARTIAL => 3,
                SaleOrderStatus::FULFILLED, SaleOrderStatus::SHIPPED, SaleOrderStatus::COMPLETED => 4,
                default => 1,
            },
            'halted'      => in_array($status, [SaleOrderStatus::CANCELLED, SaleOrderStatus::RETURNED], true),
            'statusReady' => $statusReady,
            'permitted'   => $permitted,
            'canConfirm'  => $statusReady['confirm'] && $permitted['confirm'],
            'canReserve'  => $statusReady['reserve'] && $permitted['reserve'],
            'canFulfill'  => $statusReady['fulfill'] && $permitted['fulfill'],
            'canCancel'   => $statusReady['cancel'] && $permitted['cancel'],
        ];
    }

    /**
     * The single next action for a row, combining the Sale Updater lifecycle (confirm →
     * reserve → ship) with post-ship courier tracking (dispatched → out for delivery →
     * delivered) into one priority order, so a row never offers two competing buttons
     * (e.g. "Ship" and "Mark Dispatched") at once. Shipping always comes first: courier
     * tracking only becomes available once the order has actually been shipped.
     * 'ready' means the step is next given the order's status; 'allowed' means the
     * current viewer is permitted to perform it — the view shows a button when both are
     * true, and a "you don't have permission" message when ready but not allowed.
     */
    private function primaryActionFor(SaleOrder $order, array $flow, array $meta): array
    {
        // Parcel creation is its own step before shipping: once the order is reservation-
        // complete, a viewer who may book parcels first gets "Create Parcel" (Pathao/Redx/
        // hand-carry — the modal's submit is the confirmation, so no wire:confirm), and only
        // after a tracking number exists does the action become the actual Ship (fulfil).
        // Viewers without the create-parcel gate skip straight to Ship.
        $hasParcel = filled($meta['tracking_number'] ?? null);
        $shipStep = !$hasParcel && $this->canCreateParcel()
            ? ['label' => 'Create Parcel', 'icon' => 'o-cube', 'class' => 'bg-violet-500 hover:bg-violet-600', 'method' => 'openParcelModal', 'confirm' => '', 'need' => 'create a parcel']
            : ['label' => 'Ship', 'icon' => 'o-truck', 'class' => 'bg-emerald-500 hover:bg-emerald-600', 'method' => 'fulfillSaleOrderFlow', 'confirm' => "Ship remaining quantities for {$order->so_number}?", 'need' => 'ship this order'];

        $steps = [
            'confirm' => ['label' => 'Confirm',   'icon' => 'o-check-circle',           'class' => 'bg-blue-500 hover:bg-blue-600',    'method' => 'confirmSaleOrderFlow',  'confirm' => "Confirm {$order->so_number}?", 'need' => 'confirm this order'],
            'reserve' => ['label' => 'Reserve',   'icon' => 'o-archive-box-arrow-down', 'class' => 'bg-amber-500 hover:bg-amber-600',  'method' => 'reserveSaleOrderFlow',  'confirm' => "Reserve stock for {$order->so_number}?", 'need' => 'reserve stock'],
            'fulfill' => $shipStep,
        ];

        foreach ($steps as $type => $step) {
            if ($flow['statusReady'][$type]) {
                return [
                    'type'    => $type,
                    'ready'   => true,
                    'allowed' => $flow['permitted'][$type],
                    'need'    => $step['need'],
                    'label'   => $step['label'],
                    'icon'    => $step['icon'],
                    'class'   => $step['class'],
                    'method'  => $step['method'],
                    'args'    => [$order->getKey()],
                    'call'    => "{$step['method']}(" . (string) $order->getKey() . ')',
                    'confirm' => $step['confirm'],
                ];
            }
        }

        $currentParcel = $meta['parcel_status'] ?? '';
        $isTerminalParcel = in_array($currentParcel, ['Delivery failed', 'Returned', 'Cancelled'], true);
        $canDispatchOrder = in_array($order->status?->value, self::DISPATCHABLE_STATUSES, true);

        if ($canDispatchOrder && !$isTerminalParcel) {
            $dispatchStep = match (true) {
                in_array($currentParcel, ['', 'Order confirmed', 'Reserved for picking', 'Packed', 'Ready for courier'], true) => ['action' => 'dispatched', 'label' => 'Mark Dispatched', 'icon' => 'o-paper-airplane', 'class' => 'bg-amber-500 hover:bg-amber-600'],
                $currentParcel === 'Dispatched'                                                                                => ['action' => 'out_for_delivery', 'label' => 'Out for Delivery', 'icon' => 'o-map-pin', 'class' => 'bg-blue-500 hover:bg-blue-600'],
                $currentParcel === 'Out for delivery'                                                                          => ['action' => 'delivered', 'label' => 'Mark Delivered', 'icon' => 'o-check-circle', 'class' => 'bg-emerald-500 hover:bg-emerald-600'],
                default                                                                                                        => null,
            };

            if ($dispatchStep !== null) {
                return [
                    'type'    => 'dispatch',
                    'ready'   => true,
                    'allowed' => $this->canViewDispatcherTab(),
                    'need'    => 'update dispatch tracking',
                    'label'   => $dispatchStep['label'],
                    'icon'    => $dispatchStep['icon'],
                    'class'   => $dispatchStep['class'],
                    'method'  => 'quickDispatch',
                    'args'    => [$order->getKey(), $dispatchStep['action']],
                    'call'    => 'quickDispatch(' . (string) $order->getKey() . ", '{$dispatchStep['action']}')",
                    'confirm' => "{$dispatchStep['label']} for {$order->so_number}?",
                ];
            }
        }

        return [
            'type'    => null,
            'ready'   => false,
            'allowed' => true,
            'message' => match (true) {
                $isTerminalParcel                                                                       => $currentParcel,
                in_array($order->status, [SaleOrderStatus::CANCELLED, SaleOrderStatus::RETURNED], true) => $order->status->label(),
                $canDispatchOrder                                                                       => 'Delivered',
                default                                                                                 => 'Awaiting reservation',
            },
        ];
    }

    private function canViewDispatcherTab(): bool
    {
        return auth()->user()?->can('inventory.dispatch.dispatcher-tab') ?? false;
    }

    private function canViewUpdaterTab(): bool
    {
        return auth()->user()?->can('inventory.dispatch.updater-tab') ?? false;
    }

    public function render(): View
    {
        $query = SaleOrder::query()
            ->with(['customer', 'warehouse', 'items.product'])
            ->where('document_type', 'order')
            ->latest('ordered_at')
            ->latest('id');

        match ($this->status) {
            'draft', 'confirmed', 'processing', 'partial', 'shipped', 'fulfilled', 'completed', 'cancelled', 'returned' => $query->where('status', $this->status),
            'all'   => null,
            default => $query->whereIn('status', ['draft', 'confirmed', 'processing', 'partial', 'shipped']),
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

        $saleFlow = $orders->getCollection()
            ->mapWithKeys(fn (SaleOrder $order): array => [$order->getKey() => $this->saleFlowFor($order)])
            ->all();

        $primaryActions = $orders->getCollection()
            ->mapWithKeys(fn (SaleOrder $order): array => [
                $order->getKey() => $this->primaryActionFor($order, $saleFlow[$order->getKey()], $metadata[$order->getKey()] ?? []),
            ])
            ->all();

        $parcelOrder = null;

        if ($this->parcelModalOpen && $this->parcelOrderId) {
            $parcelOrder = $orders->getCollection()->find($this->parcelOrderId)
                ?? SaleOrder::query()->with(['customer', 'warehouse', 'items.product'])->find($this->parcelOrderId);
        }

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
            'saleFlow'             => $saleFlow,
            'primaryActions'       => $primaryActions,
            'statusOptions'        => $this->statusOptions(),
            'orderStatusOptions'   => $this->orderStatusOptions(),
            'parcelStatuses'       => self::PARCEL_STATUSES,
            'summary'              => $this->summary(),
            'modelDataReady'       => $this->modelDataReady(),
            'modalOrder'           => $modalOrder,
            'modalMeta'            => $modalMeta,
            'parcelOrder'          => $parcelOrder,
            'courierApiEnabled'    => app(CourierIntegration::class)->enabled(),
            'redxAreaOptions'      => $this->parcelModalOpen ? $this->filteredRedxAreas() : [],
            'detailOrder'          => $detailOrder,
            'detailMeta'           => $detailMeta,
            'detailPriceHistory'   => $detailPriceHistory,
            'detailProductNames'   => $detailProductNames,
            'detailChartData'      => $detailChartData,
            'printOrder'           => $printOrder,
            'printMeta'            => $printMeta,
            'canViewDispatcherTab' => $this->canViewDispatcherTab(),
            'canViewUpdaterTab'    => $this->canViewUpdaterTab(),
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

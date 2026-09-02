<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Enums\SaleOrderStatus;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\SaleOrder;
use Centrex\Inventory\Support\{CommercialTeamAccess, CourierIntegration, ErpIntegration};
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class SaleOrderShowPage extends Component
{
    public string $documentType = 'order';

    public SaleOrder $record;

    public ?array $financeDocument = null;

    public ?array $linkedSaleOrder = null;

    public ?array $dispatchInfo = null;

    public bool $editingDispatch = false;

    /** @var array<string, string> */
    public array $dispatchForm = ['carrier' => '', 'tracking_number' => ''];

    public bool $trackingModalOpen = false;

    /**
     * Live parcel details from the courier API, keyed field => value.
     *
     * @var array<string, mixed>
     */
    public array $parcelInfo = [];

    /**
     * Live tracking history entries from the courier API.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $parcelTracking = [];

    public string $trackingError = '';

    private ?array $metadataCache = null;

    public function mount(int $recordId, string $documentType = 'order'): void
    {
        CommercialTeamAccess::authorizeAny(['sales.orders.manage', 'inventory.sale-orders.view', 'inventory.sale-orders.view-all']);

        $this->documentType = $documentType === 'quotation' ? 'quotation' : 'order';
        $query = SaleOrder::query()
            ->with(['customer', 'warehouse', 'items.product', 'items.variant', 'createdBy', 'salesManager', 'salesAssistantManager', 'salesExecutive', 'saleReturns.items.product', 'saleReturns.items.variant'])
            ->where('document_type', $this->documentType);

        CommercialTeamAccess::applySalesScope($query);

        $this->record = $query->findOrFail($recordId);

        $this->financeDocument = $this->resolveFinanceDocument();
        $this->linkedSaleOrder = $this->resolveLinkedSaleOrder();
        $this->dispatchInfo = $this->resolveDispatchInfo();
    }

    public function render(): View
    {
        $this->record->loadMissing(['customer', 'warehouse', 'items.product', 'items.variant', 'createdBy', 'salesManager', 'salesAssistantManager', 'salesExecutive', 'saleReturns.items.product', 'saleReturns.items.variant']);

        return view('inventory::livewire.transactions.sale-order-show', [
            'record'           => $this->record,
            'financeDocument'  => $this->financeDocument,
            'documentLabel'    => $this->documentType === 'quotation' ? 'Quotation' : 'Sale Order',
            'routeBase'        => $this->documentType === 'quotation' ? 'inventory.quotations' : 'inventory.sale-orders',
            'statusValue'      => $this->record->status?->value,
            'canConfirm'       => Gate::any(['sales.orders.manage', 'inventory.sale-orders.confirm']) && in_array($this->record->status?->value, ['draft'], true) && $this->canConfirmGivenValue(),
            'canReserve'       => Gate::any(['sales.orders.manage', 'inventory.sale-orders.reserve']) && $this->documentType === 'order' && in_array($this->record->status?->value, ['confirmed'], true),
            'canFulfill'       => Gate::any(['sales.orders.manage', 'inventory.sale-orders.fulfill']) && $this->documentType === 'order' && in_array($this->record->status?->value, ['processing', 'partial'], true),
            'canCancel'        => Gate::any(['sales.orders.manage', 'inventory.sale-orders.cancel']) && in_array($this->record->status?->value, ['draft', 'confirmed', 'processing', 'partial'], true),
            'canEdit'          => Gate::any(['sales.orders.manage', 'inventory.sale-orders.edit']),
            'canCreateInvoice' => $this->documentType === 'order'
                && $this->financeDocument === null
                && $this->record->status?->value !== 'cancelled'
                && Gate::allows('accounting.invoice.create'),
            'canCreateSaleOrder' => $this->documentType === 'quotation'
                && $this->record->status?->value === 'confirmed'
                && $this->linkedSaleOrder === null,
            'linkedSaleOrder'   => $this->linkedSaleOrder,
            'dispatchInfo'      => $this->dispatchInfo,
            'trackingLink'      => $this->resolveTrackingLink(),
            'trackingModalOpen' => $this->trackingModalOpen,
            'parcelInfo'        => $this->parcelInfo,
            'parcelTracking'    => $this->parcelTracking,
            'trackingError'     => $this->trackingError,
            'saleFlowSteps'     => $this->saleFlowSteps(),
            'saleFlowCurrent'   => $this->saleFlowCurrentStep(),
            'saleFlowHalted'    => in_array($this->record->status, [SaleOrderStatus::CANCELLED, SaleOrderStatus::RETURNED], true),
            'modelDataReady'    => $this->modelDataReady(),
        ]);
    }

    /** Draft -> Confirmed -> Reserved -> Fulfilled pipeline shown to sale updaters progressing an order. */
    private function saleFlowSteps(): array
    {
        if ($this->documentType !== 'order') {
            return [];
        }

        return [
            ['label' => 'Draft'],
            ['label' => 'Confirmed'],
            ['label' => 'Reserved'],
            ['label' => 'Fulfilled'],
        ];
    }

    private function saleFlowCurrentStep(): int
    {
        return match ($this->record->status) {
            SaleOrderStatus::CONFIRMED => 2,
            SaleOrderStatus::PROCESSING, SaleOrderStatus::PARTIAL => 3,
            SaleOrderStatus::FULFILLED, SaleOrderStatus::SHIPPED, SaleOrderStatus::COMPLETED => 4,
            default => 1,
        };
    }

    public function confirm(): void
    {
        CommercialTeamAccess::authorizeAny(['sales.orders.manage', 'inventory.sale-orders.confirm']);

        try {
            app(Inventory::class)->confirmSaleOrder((int) $this->record->getKey());
            $this->refreshRecord();

            $this->dispatch('notify', type: 'success', message: "{$this->record->so_number} confirmed.");
        } catch (\Throwable $exception) {
            $this->dispatch('notify', type: 'error', message: $exception->getMessage());
        }
    }

    /** Mirrors Inventory::assertHighValueConfirmAuthorized() so the button hides for orders the user can't confirm. */
    private function canConfirmGivenValue(): bool
    {
        $threshold = (float) config('inventory.sale_order_high_value_threshold', 0);

        if ($threshold <= 0 || (float) $this->record->total_amount < $threshold) {
            return true;
        }

        return Gate::allows('inventory.sale-orders.confirm-high-value');
    }

    public function reserve(): void
    {
        CommercialTeamAccess::authorizeAny(['sales.orders.manage', 'inventory.sale-orders.reserve']);

        try {
            app(Inventory::class)->reserveStock((int) $this->record->getKey());
            $this->refreshRecord();

            $this->dispatch('notify', type: 'success', message: "Stock reserved for {$this->record->so_number}.");
        } catch (\Throwable $exception) {
            $this->dispatch('notify', type: 'error', message: $exception->getMessage());
        }
    }

    public function fulfill(): void
    {
        CommercialTeamAccess::authorizeAny(['sales.orders.manage', 'inventory.sale-orders.fulfill']);

        try {
            app(Inventory::class)->fulfillSaleOrder((int) $this->record->getKey());
            $this->refreshRecord();

            // fulfillSaleOrder() syncs and posts the accounting invoice itself (see
            // ErpIntegration::postSaleOrderInvoice()), so $this->financeDocument already
            // reflects the posted invoice by the time refreshRecord() re-resolves it above.
            if ($this->financeDocument !== null && $this->financeDocument['status_raw'] === 'issued') {
                $this->dispatch('notify', type: 'success', message: "{$this->record->so_number} fulfilled and invoice {$this->financeDocument['number']} posted.");
            } else {
                $this->dispatch('notify', type: 'success', message: "{$this->record->so_number} fulfilled.");
            }
        } catch (\Throwable $exception) {
            $this->dispatch('notify', type: 'error', message: $exception->getMessage());
        }
    }

    public function cancel(): void
    {
        CommercialTeamAccess::authorizeAny(['sales.orders.manage', 'inventory.sale-orders.cancel']);

        $this->runWorkflowAction(
            fn (Inventory $inventory) => $inventory->cancelSaleOrder((int) $this->record->getKey()),
            "{$this->record->so_number} cancelled.",
        );
    }

    public function createInvoice(): void
    {
        try {
            Gate::authorize('accounting.invoice.create');

            if ($this->record->status?->value === 'cancelled') {
                throw new \RuntimeException('Cannot create an invoice for a cancelled sale order.');
            }

            $erp = app(ErpIntegration::class);

            if (!$erp->enabled()) {
                throw new \RuntimeException('Accounting integration is disabled. Enable INVENTORY_ACCOUNTING_ENABLED to create invoices from sale orders.');
            }

            $invoiceId = $erp->syncSaleOrderDocument($this->record);

            if (!$invoiceId) {
                throw new \RuntimeException('Unable to create an accounting invoice for this sale order.');
            }

            $this->refreshRecord();
            $this->dispatch('notify', type: 'success', message: "Invoice created for {$this->record->so_number}.");
        } catch (\Throwable $exception) {
            $this->dispatch('notify', type: 'error', message: $exception->getMessage());
        }
    }

    public function createSaleOrder(): mixed
    {
        try {
            CommercialTeamAccess::authorizeAny(['sales.orders.manage', 'inventory.sale-orders.create']);

            $saleOrder = app(Inventory::class)->createSaleOrderFromQuotation((int) $this->record->getKey());
            $this->dispatch('notify', type: 'success', message: "Sale order {$saleOrder->so_number} created from {$this->record->so_number}.");

            return redirect()->route('inventory.sale-orders.show', ['recordId' => $saleOrder->getKey()]);
        } catch (\Throwable $exception) {
            $this->dispatch('notify', type: 'error', message: $exception->getMessage());

            return null;
        }
    }

    /**
     * Sets the form up from the current metadata (not the possibly-null $dispatchInfo, so
     * a carrier saved without a tracking number — see resolveDispatchInfo() — still round-trips).
     */
    public function editDispatchInfo(): void
    {
        CommercialTeamAccess::authorizeAny(['sales.orders.manage', 'inventory.sale-orders.edit']);

        $data = $this->documentMetadata();

        $this->dispatchForm = [
            'carrier'         => $data['carrier'] ?? '',
            'tracking_number' => $data['tracking_number'] ?? '',
        ];
        $this->editingDispatch = true;
    }

    public function cancelEditDispatchInfo(): void
    {
        $this->editingDispatch = false;
    }

    /**
     * Only touches carrier/tracking_number — every other dispatch field (parcel_status, eta,
     * location, dispatch_note, ...) is left as whatever the Dispatch Terminal last wrote, so
     * this lightweight form can't accidentally blank out state set from there.
     */
    public function saveDispatchInfo(): void
    {
        CommercialTeamAccess::authorizeAny(['sales.orders.manage', 'inventory.sale-orders.edit']);

        if (!$this->modelDataReady()) {
            $this->dispatch('notify', type: 'error', message: 'Model data storage is not ready, so tracking info cannot be saved yet.');

            return;
        }

        $validated = validator($this->dispatchForm, [
            'carrier'         => ['nullable', 'string', 'max:80'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
        ])->validate();

        $data = array_merge($this->documentMetadata(), [
            'carrier'         => filled($validated['carrier'] ?? null) ? $validated['carrier'] : null,
            'tracking_number' => filled($validated['tracking_number'] ?? null) ? $validated['tracking_number'] : null,
        ]);

        $modelType = $this->record->getMorphClass();
        $modelId = $this->record->getKey();
        $key = \Centrex\ModelData\Models\Data::generateKey($modelType, $modelId, 'data');

        \Centrex\ModelData\Models\Data::query()->updateOrCreate(
            ['key' => $key],
            ['key' => $key, 'data_type' => 'data', 'model_type' => $modelType, 'model_id' => $modelId, 'data' => $data],
        );

        $this->editingDispatch = false;
        $this->refreshRecord();

        $this->dispatch('notify', type: 'success', message: 'Tracking info updated.');
    }

    private function modelDataReady(): bool
    {
        return class_exists(\Centrex\ModelData\Models\Data::class);
    }

    /**
     * Opens the parcel-tracking modal and pulls the parcel's live details and tracking
     * history from the courier API (Redx / Pathao) for this order's booked tracking number —
     * mirrors DispatchTerminalPage::openTrackingModal(), but against $this->record instead of
     * a row picked from a paginated list.
     */
    public function openTrackingModal(): void
    {
        $data = $this->documentMetadata();

        $this->trackingModalOpen = true;
        $this->parcelInfo = [];
        $this->parcelTracking = [];
        $this->trackingError = '';

        $provider = (string) ($data['courier_provider'] ?? '');
        $environment = (string) ($data['courier_environment'] ?? 'sandbox');
        $trackingNumber = (string) ($data['tracking_number'] ?? '');

        if ($trackingNumber === '' || !in_array($provider, ['redx', 'pathao'], true)) {
            $this->trackingError = 'This order has no courier-booked parcel — tracking is only available for Redx and Pathao parcels.';

            return;
        }

        try {
            $details = app(CourierIntegration::class)->parcelDetails(
                $provider,
                $environment,
                $trackingNumber,
                (string) ($this->record->customer?->phone ?? data_get($data, 'shipping_address.phone', '')),
            );

            $this->parcelInfo = $details['info'];
            $this->parcelTracking = $details['tracking'];

            if ($this->parcelInfo === [] && $this->parcelTracking === []) {
                $this->trackingError = "The courier returned no data for parcel {$trackingNumber} yet.";
            }
        } catch (\Throwable $exception) {
            $this->trackingError = "Could not load parcel data: {$exception->getMessage()}";
        }
    }

    public function closeTrackingModal(): void
    {
        $this->trackingModalOpen = false;
        $this->parcelInfo = [];
        $this->parcelTracking = [];
        $this->trackingError = '';
    }

    /** Public, customer-facing courier tracking-page URL — see CourierIntegration::trackingLink(). */
    private function resolveTrackingLink(): ?string
    {
        $data = $this->documentMetadata();
        $trackingNumber = (string) ($data['tracking_number'] ?? '');
        $provider = (string) ($data['courier_provider'] ?? '');

        if ($trackingNumber === '' || $provider === '') {
            return null;
        }

        return app(CourierIntegration::class)->trackingLink(
            $provider,
            $trackingNumber,
            (string) ($this->record->customer?->phone ?? data_get($data, 'shipping_address.phone', '')),
        );
    }

    protected function resolveFinanceDocument(): ?array
    {
        $invoiceClass = \Centrex\Accounting\Models\Invoice::class;

        if (!class_exists($invoiceClass)) {
            return null;
        }

        $invoice = $this->record->accounting_invoice_id
            ? $invoiceClass::query()
                ->with(['payments.journalEntry'])
                ->find($this->record->accounting_invoice_id)
            : $invoiceClass::query()
                ->with(['payments.journalEntry'])
                ->where(function ($query): void {
                    $query->where('source_type', SaleOrder::class)
                        ->where('source_id', $this->record->getKey());
                })
                ->orWhere('inventory_sale_order_id', $this->record->getKey())
                ->first();

        if (!$invoice) {
            return null;
        }

        $status = $invoice->status->value ?? (string) $invoice->status;

        return [
            'id'         => (int) $invoice->getKey(),
            'number'     => (string) $invoice->invoice_number,
            'status'     => ucfirst(str_replace('_', ' ', $status)),
            'status_raw' => strtolower((string) $status),
            'total'      => (float) $invoice->total,
            'paid'       => (float) $invoice->paid_amount,
            'balance'    => (float) $invoice->balance,
            'due_date'   => $invoice->due_date?->format('M d, Y') ?? '—',
            'is_due'     => (float) $invoice->balance > 0,
            'payments'   => $invoice->payments
                ->sortByDesc(fn ($payment) => $payment->payment_date?->getTimestamp() ?? 0)
                ->values()
                ->map(fn ($payment): array => [
                    'date'          => $payment->payment_date?->format('M d, Y') ?? '—',
                    'method'        => str((string) $payment->payment_method)->replace('_', ' ')->title()->toString(),
                    'reference'     => $payment->reference ?: null,
                    'notes'         => $payment->notes ?: null,
                    'amount'        => (float) $payment->amount,
                    'journal_entry' => $payment->journalEntry?->entry_number ?: null,
                ])
                ->all(),
        ];
    }

    private function runWorkflowAction(callable $callback, string $successMessage): void
    {
        try {
            $callback(app(Inventory::class));
            $this->refreshRecord();
            $this->dispatch('notify', type: 'success', message: $successMessage);
        } catch (\Throwable $exception) {
            $this->dispatch('notify', type: 'error', message: $exception->getMessage());
        }
    }

    private function refreshRecord(): void
    {
        $this->record = SaleOrder::query()
            ->with(['customer', 'warehouse', 'items.product', 'items.variant', 'createdBy', 'salesManager', 'salesAssistantManager', 'salesExecutive', 'saleReturns.items.product', 'saleReturns.items.variant'])
            ->where('document_type', $this->documentType)
            ->findOrFail($this->record->getKey());

        $this->metadataCache = null;
        $this->financeDocument = $this->resolveFinanceDocument();
        $this->linkedSaleOrder = $this->resolveLinkedSaleOrder();
        $this->dispatchInfo = $this->resolveDispatchInfo();
    }

    /**
     * Carrier/tracking/delivery-status data recorded via the Dispatch Terminal — stored in the
     * same polymorphic Data record as $this->documentMetadata(), not on the SaleOrder itself.
     */
    private function resolveDispatchInfo(): ?array
    {
        $data = $this->documentMetadata();

        if (!filled($data['tracking_number'] ?? null) && !filled($data['parcel_status'] ?? null) && !filled($data['carrier'] ?? null)) {
            return null;
        }

        return [
            'carrier'             => $data['carrier'] ?? null,
            'tracking_number'     => $data['tracking_number'] ?? null,
            'parcel_status'       => $data['parcel_status'] ?? null,
            'eta'                 => $data['eta'] ?? null,
            'location'            => $data['location'] ?? null,
            'dispatch_note'       => $data['dispatch_note'] ?? null,
            'dispatched_by'       => $data['dispatched_by'] ?? null,
            'dispatch_updated_at' => $data['dispatch_updated_at'] ?? null,
            'courier_provider'    => $data['courier_provider'] ?? null,
        ];
    }

    private function resolveLinkedSaleOrder(): ?array
    {
        $saleOrderId = (int) ($this->documentMetadata()['converted_sale_order_id'] ?? 0);

        if ($saleOrderId <= 0) {
            return null;
        }

        $saleOrder = SaleOrder::query()
            ->where('document_type', 'order')
            ->find($saleOrderId);

        if (!$saleOrder) {
            return null;
        }

        return [
            'id'     => (int) $saleOrder->getKey(),
            'number' => (string) $saleOrder->so_number,
        ];
    }

    private function documentMetadata(): array
    {
        if ($this->metadataCache !== null) {
            return $this->metadataCache;
        }

        if (!$this->modelDataReady()) {
            return $this->metadataCache = [];
        }

        $record = \Centrex\ModelData\Models\Data::query()
            ->forModel($this->record)
            ->first();

        if (!$record) {
            return $this->metadataCache = [];
        }

        return $this->metadataCache = is_array($record->data)
            ? $record->data
            : (json_decode((string) $record->data, true) ?: []);
    }
}

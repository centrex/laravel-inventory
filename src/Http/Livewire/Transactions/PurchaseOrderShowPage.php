<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\PurchaseOrder;
use Centrex\Inventory\Support\ErpIntegration;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PurchaseOrderShowPage extends Component
{
    public string $documentType = 'order';

    public PurchaseOrder $record;

    public ?array $financeDocument = null;

    public function mount(int $recordId, string $documentType = 'order'): void
    {
        $this->documentType = $documentType === 'requisition' ? 'requisition' : 'order';
        $this->record = PurchaseOrder::query()
            ->with(['supplier', 'warehouse', 'items.product'])
            ->where('document_type', $this->documentType)
            ->findOrFail($recordId);

        $this->financeDocument = $this->resolveFinanceDocument();
    }

    public function render(): View
    {
        $this->record->loadMissing(['supplier', 'warehouse', 'items.product']);

        return view('inventory::livewire.transactions.purchase-order-show', [
            'record'          => $this->record,
            'financeDocument' => $this->financeDocument,
            'documentLabel'   => $this->documentType === 'requisition' ? 'Requisition' : 'Purchase Order',
            'routeBase'       => $this->documentType === 'requisition' ? 'inventory.requisitions' : 'inventory.purchase-orders',
            'statusValue'     => $this->record->status?->value,
            'canSubmit'       => in_array($this->record->status?->value, ['draft'], true),
            'canConfirm'      => in_array($this->record->status?->value, ['submitted'], true),
            'canReceive'      => $this->documentType === 'order' && in_array($this->record->status?->value, ['confirmed', 'partial'], true),
            'canCancel'       => in_array($this->record->status?->value, ['draft', 'submitted', 'confirmed', 'partial'], true),
            'canCreateBill'   => $this->documentType === 'order' && $this->financeDocument === null,
        ]);
    }

    public function submit(): void
    {
        $this->runWorkflowAction(
            fn (Inventory $inventory) => $inventory->submitPurchaseOrder((int) $this->record->getKey()),
            "{$this->record->po_number} submitted.",
        );
    }

    public function confirm(): void
    {
        $this->runWorkflowAction(
            fn (Inventory $inventory) => $inventory->confirmPurchaseOrder((int) $this->record->getKey()),
            "{$this->record->po_number} confirmed.",
        );
    }

    public function receive(): void
    {
        $this->runWorkflowAction(
            fn (Inventory $inventory) => $inventory->receivePurchaseOrder((int) $this->record->getKey()),
            "Items received for {$this->record->po_number}.",
        );
    }

    public function cancel(): void
    {
        $this->runWorkflowAction(
            fn (Inventory $inventory) => $inventory->cancelPurchaseOrder((int) $this->record->getKey()),
            "{$this->record->po_number} cancelled.",
        );
    }

    public function createBill(): void
    {
        try {
            $erp = app(ErpIntegration::class);

            if (!$erp->enabled()) {
                throw new \RuntimeException('Accounting integration is disabled. Enable INVENTORY_ACCOUNTING_ENABLED to create bills from purchase orders.');
            }

            $billId = $erp->syncPurchaseOrderDocument($this->record);

            if (!$billId) {
                throw new \RuntimeException('Unable to create an accounting bill for this purchase order.');
            }

            $this->refreshRecord();
            session()->flash('inventory.status', "Bill created for {$this->record->po_number}.");
        } catch (\Throwable $exception) {
            session()->flash('inventory.error', $exception->getMessage());
        }
    }

    protected function resolveFinanceDocument(): ?array
    {
        $billClass = \Centrex\Accounting\Models\Bill::class;

        if (!class_exists($billClass)) {
            return null;
        }

        $bill = $this->record->accounting_bill_id
            ? $billClass::query()
                ->with(['payments.journalEntry'])
                ->find($this->record->accounting_bill_id)
            : $billClass::query()
                ->with(['payments.journalEntry'])
                ->where('inventory_purchase_order_id', $this->record->getKey())
                ->first();

        if (!$bill) {
            return null;
        }

        $status = $bill->status->value ?? (string) $bill->status;

        return [
            'id'         => (int) $bill->getKey(),
            'number'     => (string) $bill->bill_number,
            'status'     => ucfirst(str_replace('_', ' ', $status)),
            'status_raw' => strtolower((string) $status),
            'total'      => (float) $bill->total,
            'paid'       => (float) $bill->paid_amount,
            'balance'    => (float) $bill->balance,
            'due_date'   => $bill->due_date?->format('M d, Y') ?? '—',
            'is_due'     => (float) $bill->balance > 0,
            'payments'   => $bill->payments
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
            session()->flash('inventory.status', $successMessage);
        } catch (\Throwable $exception) {
            session()->flash('inventory.error', $exception->getMessage());
        }
    }

    private function refreshRecord(): void
    {
        $this->record = PurchaseOrder::query()
            ->with(['supplier', 'warehouse', 'items.product'])
            ->where('document_type', $this->documentType)
            ->findOrFail($this->record->getKey());

        $this->financeDocument = $this->resolveFinanceDocument();
    }
}

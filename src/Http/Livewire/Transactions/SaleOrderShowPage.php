<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\SaleOrder;
use Centrex\Inventory\Support\ErpIntegration;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class SaleOrderShowPage extends Component
{
    public string $documentType = 'order';

    public SaleOrder $record;

    public ?array $financeDocument = null;

    public function mount(int $recordId, string $documentType = 'order'): void
    {
        $this->documentType = $documentType === 'quotation' ? 'quotation' : 'order';
        $this->record = SaleOrder::query()
            ->with(['customer', 'warehouse', 'items.product'])
            ->where('document_type', $this->documentType)
            ->findOrFail($recordId);

        $this->financeDocument = $this->resolveFinanceDocument();
    }

    public function render(): View
    {
        $this->record->loadMissing(['customer', 'warehouse', 'items.product']);

        return view('inventory::livewire.transactions.sale-order-show', [
            'record'           => $this->record,
            'financeDocument'  => $this->financeDocument,
            'documentLabel'    => $this->documentType === 'quotation' ? 'Quotation' : 'Sale Order',
            'routeBase'        => $this->documentType === 'quotation' ? 'inventory.quotations' : 'inventory.sale-orders',
            'statusValue'      => $this->record->status?->value,
            'canConfirm'       => in_array($this->record->status?->value, ['draft'], true),
            'canReserve'       => $this->documentType === 'order' && in_array($this->record->status?->value, ['confirmed'], true),
            'canFulfill'       => $this->documentType === 'order' && in_array($this->record->status?->value, ['processing', 'partial'], true),
            'canCancel'        => in_array($this->record->status?->value, ['draft', 'confirmed', 'processing', 'partial'], true),
            'canCreateInvoice' => $this->documentType === 'order' && $this->financeDocument === null,
        ]);
    }

    public function confirm(): void
    {
        $this->runWorkflowAction(
            fn (Inventory $inventory) => $inventory->confirmSaleOrder((int) $this->record->getKey()),
            "{$this->record->so_number} confirmed.",
        );
    }

    public function reserve(): void
    {
        $this->runWorkflowAction(
            fn (Inventory $inventory) => $inventory->reserveStock((int) $this->record->getKey()),
            "Stock reserved for {$this->record->so_number}.",
        );
    }

    public function fulfill(): void
    {
        $this->runWorkflowAction(
            fn (Inventory $inventory) => $inventory->fulfillSaleOrder((int) $this->record->getKey()),
            "{$this->record->so_number} fulfilled.",
        );
    }

    public function cancel(): void
    {
        $this->runWorkflowAction(
            fn (Inventory $inventory) => $inventory->cancelSaleOrder((int) $this->record->getKey()),
            "{$this->record->so_number} cancelled.",
        );
    }

    public function createInvoice(): void
    {
        try {
            $erp = app(ErpIntegration::class);

            if (!$erp->enabled()) {
                throw new \RuntimeException('Accounting integration is disabled. Enable INVENTORY_ACCOUNTING_ENABLED to create invoices from sale orders.');
            }

            $invoiceId = $erp->syncSaleOrderDocument($this->record);

            if (!$invoiceId) {
                throw new \RuntimeException('Unable to create an accounting invoice for this sale order.');
            }

            $this->refreshRecord();
            session()->flash('inventory.status', "Invoice created for {$this->record->so_number}.");
        } catch (\Throwable $exception) {
            session()->flash('inventory.error', $exception->getMessage());
        }
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
                ->where('inventory_sale_order_id', $this->record->getKey())
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
            session()->flash('inventory.status', $successMessage);
        } catch (\Throwable $exception) {
            session()->flash('inventory.error', $exception->getMessage());
        }
    }

    private function refreshRecord(): void
    {
        $this->record = SaleOrder::query()
            ->with(['customer', 'warehouse', 'items.product'])
            ->where('document_type', $this->documentType)
            ->findOrFail($this->record->getKey());

        $this->financeDocument = $this->resolveFinanceDocument();
    }
}

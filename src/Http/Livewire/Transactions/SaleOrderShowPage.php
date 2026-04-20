<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Models\SaleOrder;
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
            ->with(['customer', 'warehouse', 'priceTier', 'items.product'])
            ->where('document_type', $this->documentType)
            ->findOrFail($recordId);

        $this->financeDocument = $this->resolveFinanceDocument();
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.sale-order-show', [
            'record'          => $this->record,
            'financeDocument' => $this->financeDocument,
            'documentLabel'   => $this->documentType === 'quotation' ? 'Quotation' : 'Sale Order',
            'routeBase'       => $this->documentType === 'quotation' ? 'inventory.quotations' : 'inventory.sale-orders',
        ]);
    }

    protected function resolveFinanceDocument(): ?array
    {
        $invoiceClass = \Centrex\Accounting\Models\Invoice::class;

        if (!class_exists($invoiceClass)) {
            return null;
        }

        $invoice = $this->record->accounting_invoice_id
            ? $invoiceClass::query()->find($this->record->accounting_invoice_id)
            : $invoiceClass::query()->where('inventory_sale_order_id', $this->record->getKey())->first();

        if (!$invoice) {
            return null;
        }

        $status = $invoice->status->value ?? (string) $invoice->status;

        return [
            'id'       => (int) $invoice->getKey(),
            'number'   => (string) $invoice->invoice_number,
            'status'   => ucfirst(str_replace('_', ' ', $status)),
            'total'    => (float) $invoice->total,
            'paid'     => (float) $invoice->paid_amount,
            'balance'  => (float) $invoice->balance,
            'due_date' => $invoice->due_date?->format('M d, Y') ?? '—',
            'is_due'   => (float) $invoice->balance > 0,
        ];
    }
}

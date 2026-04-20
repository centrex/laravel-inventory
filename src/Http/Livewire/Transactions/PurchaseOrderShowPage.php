<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Models\PurchaseOrder;
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
        return view('inventory::livewire.transactions.purchase-order-show', [
            'record'          => $this->record,
            'financeDocument' => $this->financeDocument,
            'documentLabel'   => $this->documentType === 'requisition' ? 'Requisition' : 'Purchase Order',
            'routeBase'       => $this->documentType === 'requisition' ? 'inventory.requisitions' : 'inventory.purchase-orders',
        ]);
    }

    protected function resolveFinanceDocument(): ?array
    {
        $billClass = \Centrex\Accounting\Models\Bill::class;

        if (!class_exists($billClass)) {
            return null;
        }

        $bill = $this->record->accounting_bill_id
            ? $billClass::query()->find($this->record->accounting_bill_id)
            : $billClass::query()->where('inventory_purchase_order_id', $this->record->getKey())->first();

        if (!$bill) {
            return null;
        }

        $status = $bill->status->value ?? (string) $bill->status;

        return [
            'id'       => (int) $bill->getKey(),
            'number'   => (string) $bill->bill_number,
            'status'   => ucfirst(str_replace('_', ' ', $status)),
            'total'    => (float) $bill->total,
            'paid'     => (float) $bill->paid_amount,
            'balance'  => (float) $bill->balance,
            'due_date' => $bill->due_date?->format('M d, Y') ?? '—',
            'is_due'   => (float) $bill->balance > 0,
        ];
    }
}

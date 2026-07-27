<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Exceptions\InsufficientStockException;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Supplier, Transfer};
use Centrex\Inventory\Support\ErpIntegration;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class TransferShowPage extends Component
{
    public Transfer $record;

    public bool $showReceiveModal = false;

    public array $receiveQtys = [];

    public ?array $financeDocument = null;

    public ?int $supplier_id = null;

    public function mount(int $recordId): void
    {
        $this->record = Transfer::query()
            ->with($this->relations())
            ->findOrFail($recordId);

        $this->supplier_id = $this->record->supplier_id;
        $this->financeDocument = $this->resolveFinanceDocument();
    }

    /** Set/change the courier/vendor — needed since a transfer has no separate edit page. */
    public function updateSupplier(): void
    {
        $this->record->update(['supplier_id' => $this->supplier_id ?: null]);
        $this->refreshRecord();
        $this->dispatch('notify', type: 'success', message: 'Courier/vendor updated.');
    }

    public function createBill(): void
    {
        try {
            $erp = app(ErpIntegration::class);

            if (!$erp->enabled()) {
                throw new \RuntimeException('Accounting integration is disabled. Enable INVENTORY_ACCOUNTING_ENABLED to create bills from transfers.');
            }

            if (!$this->record->supplier_id) {
                throw new \RuntimeException('Set a courier/vendor for this transfer before creating a bill.');
            }

            if ((float) $this->record->shipping_cost_amount <= 0) {
                throw new \RuntimeException('This transfer has no shipping cost yet — nothing to bill.');
            }

            $billId = $erp->syncTransferDocument($this->record);

            if (!$billId) {
                throw new \RuntimeException('Unable to create an accounting bill for this transfer.');
            }

            $this->refreshRecord();
            $this->financeDocument = $this->resolveFinanceDocument();
            $this->dispatch('notify', type: 'success', message: "Bill created for {$this->record->transfer_number}.");
        } catch (\Throwable $exception) {
            $this->dispatch('notify', type: 'error', message: $exception->getMessage());
        }
    }

    public function dispatchTransfer(): void
    {
        Gate::authorize('inventory.transfers.dispatch');

        try {
            $this->record = app(Inventory::class)->dispatchTransfer((int) $this->record->getKey());
            $this->dispatch('notify', type: 'success', message: "Transfer {$this->record->transfer_number} dispatched.");
        } catch (InsufficientStockException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    public function openReceiveModal(): void
    {
        $this->receiveQtys = [];

        foreach ($this->record->items as $item) {
            $remaining = max(0.0, (float) $item->qty_sent - (float) $item->qty_received);
            $this->receiveQtys[$item->id] = $remaining;
        }

        $this->showReceiveModal = true;
    }

    public function receivePartial(): void
    {
        Gate::authorize('inventory.transfers.receive');

        $qtys = collect($this->receiveQtys)
            ->filter(fn ($v) => (float) $v > 0)
            ->map(fn ($v) => (float) $v)
            ->all();

        $this->record = app(Inventory::class)->receiveTransfer((int) $this->record->getKey(), $qtys);
        $this->showReceiveModal = false;
        $this->dispatch('notify', type: 'success', message: "Transfer {$this->record->transfer_number} updated.");
    }

    public function receiveAll(): void
    {
        Gate::authorize('inventory.transfers.receive');

        $this->record = app(Inventory::class)->receiveTransfer((int) $this->record->getKey());
        $this->dispatch('notify', type: 'success', message: "Transfer {$this->record->transfer_number} received.");
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.transfer-show', [
            'record'          => $this->refreshRecord(),
            'canDispatch'     => Gate::allows('inventory.transfers.dispatch'),
            'canReceive'      => Gate::allows('inventory.transfers.receive'),
            'financeDocument' => $this->financeDocument,
            'canCreateBill'   => $this->financeDocument === null,
            'suppliers'       => Supplier::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    private function refreshRecord(): Transfer
    {
        $this->record = $this->record->fresh($this->relations());
        $this->supplier_id = $this->record->supplier_id;

        return $this->record;
    }

    private function relations(): array
    {
        return ['fromWarehouse', 'toWarehouse', 'supplier', 'items.product', 'boxes.items.product'];
    }

    private function resolveFinanceDocument(): ?array
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
                ->where('source_type', Transfer::class)
                ->where('source_id', $this->record->getKey())
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
}

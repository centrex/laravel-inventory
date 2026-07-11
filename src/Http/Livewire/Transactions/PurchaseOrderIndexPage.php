<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Concerns\ShowsAuditTrail;
use Centrex\Inventory\Models\PurchaseOrder;
use Centrex\Inventory\Support\CommercialTeamAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\{Component, WithPagination};

#[Layout('layouts.app')]
class PurchaseOrderIndexPage extends Component
{
    use ShowsAuditTrail;
    use WithPagination;

    public string $documentType = 'order';

    public string $search = '';

    public string $status = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function mount(string $documentType = 'order'): void
    {
        Gate::authorize('inventory.purchase-orders.view');

        $this->documentType = $documentType === 'requisition' ? 'requisition' : 'order';
    }

    public function render(): View
    {
        $query = PurchaseOrder::query()
            ->with(['supplier', 'warehouse'])
            ->where('document_type', $this->documentType)
            ->latest('created_at')
            ->latest('id');

        CommercialTeamAccess::applyPurchaseScope($query);

        if ($this->search !== '') {
            $search = $this->search;
            $query->where(function ($builder) use ($search): void {
                $builder->where('po_number', 'like', '%' . $search . '%')
                    ->orWhere('notes', 'like', '%' . $search . '%')
                    ->orWhereHas('supplier', fn ($supplierQuery) => $supplierQuery->where('name', 'like', '%' . $search . '%'))
                    ->orWhereHas('warehouse', fn ($warehouseQuery) => $warehouseQuery->where('name', 'like', '%' . $search . '%'));
            });
        }

        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        $orders = $query->paginate(15);

        return view('inventory::livewire.transactions.purchase-order-index', [
            'orders'        => $orders,
            'dueAmounts'    => $this->resolveDueAmounts($orders),
            'documentLabel' => $this->documentType === 'requisition' ? 'Requisitions' : 'Purchase Orders',
            'routeBase'     => $this->documentType === 'requisition' ? 'inventory.requisitions' : 'inventory.purchase-orders',
            'statusOptions' => [
                'draft'     => 'Draft',
                'submitted' => 'Submitted',
                'confirmed' => 'Confirmed',
                'partial'   => 'Partially Received',
                'received'  => 'Received',
                'cancelled' => 'Cancelled',
            ],
        ]);
    }

    /**
     * Resolves outstanding balance per purchase order via the linked accounting Bill
     * (matched by accounting_bill_id, source polymorphic reference, or inventory_purchase_order_id).
     * Orders without a bill yet are assumed fully due (nothing paid).
     */
    private function resolveDueAmounts(\Illuminate\Contracts\Pagination\LengthAwarePaginator $orders): array
    {
        $billClass = \Centrex\Accounting\Models\Bill::class;

        if (!class_exists($billClass)) {
            return [];
        }

        $poIds = $orders->pluck('id')->all();
        $billIds = $orders->pluck('accounting_bill_id')->filter()->all();

        if ($poIds === []) {
            return [];
        }

        $bills = $billClass::query()
            ->where(function ($query) use ($poIds, $billIds): void {
                if ($billIds !== []) {
                    $query->orWhereIn('id', $billIds);
                }

                $query->orWhere(function ($sourceQuery) use ($poIds): void {
                    $sourceQuery->where('source_type', PurchaseOrder::class)->whereIn('source_id', $poIds);
                });
                $query->orWhereIn('inventory_purchase_order_id', $poIds);
            })
            ->get();

        $dueAmounts = [];

        foreach ($orders as $order) {
            $bill = $bills->first(fn ($candidate): bool => (int) $candidate->getKey() === (int) $order->accounting_bill_id
                || ((string) $candidate->source_type === PurchaseOrder::class && (int) $candidate->source_id === (int) $order->getKey())
                || (int) $candidate->inventory_purchase_order_id === (int) $order->getKey());

            if ($bill !== null) {
                $dueAmounts[$order->getKey()] = (float) $bill->balance;
            }
        }

        return $dueAmounts;
    }
}

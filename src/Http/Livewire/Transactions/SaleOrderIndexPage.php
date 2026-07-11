<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Concerns\ShowsAuditTrail;
use Centrex\Inventory\Models\SaleOrder;
use Centrex\Inventory\Support\CommercialTeamAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\{Component, WithPagination};
use OwenIt\Auditing\Models\Audit as DefaultAudit;

#[Layout('layouts.app')]
class SaleOrderIndexPage extends Component
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

    /** Returns the subset of the given IDs that have at least one audit record. One query per page load. */
    private function resolveAuditedIds(array $ids): array
    {
        if (empty($ids) || !$this->supportsAuditTrail(SaleOrder::class)) {
            return [];
        }

        $auditClass = config('audit.implementation', DefaultAudit::class);

        if (!is_string($auditClass) || !class_exists($auditClass)) {
            return [];
        }

        try {
            return $auditClass::query()
                ->where('auditable_type', SaleOrder::class)
                ->whereIn('auditable_id', $ids)
                ->distinct()
                ->pluck('auditable_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function mount(string $documentType = 'order'): void
    {
        CommercialTeamAccess::authorizeAny(['sales.orders.manage', 'inventory.sale-orders.view', 'inventory.sale-orders.view-all']);

        $this->documentType = $documentType === 'quotation' ? 'quotation' : 'order';
    }

    public function render(): View
    {
        $query = SaleOrder::query()
            ->with(['customer', 'warehouse'])
            ->where('document_type', $this->documentType)
            ->latest('ordered_at')
            ->latest('id');

        CommercialTeamAccess::applySalesScope($query);

        if ($this->search !== '') {
            $search = $this->search;
            $query->where(function ($builder) use ($search): void {
                $builder->where('so_number', 'like', '%' . $search . '%')
                    ->orWhere('notes', 'like', '%' . $search . '%')
                    ->orWhereHas('customer', fn ($customerQuery) => $customerQuery->where('name', 'like', '%' . $search . '%')
                        ->orWhere('organization_name', 'like', '%' . $search . '%'))
                    ->orWhereHas('warehouse', fn ($warehouseQuery) => $warehouseQuery->where('name', 'like', '%' . $search . '%'));
            });
        }

        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        $orders = $query->paginate(15);

        $auditedIds = $this->resolveAuditedIds($orders->getCollection()->pluck('id')->all());

        return view('inventory::livewire.transactions.sale-order-index', [
            'orders'        => $orders,
            'auditedIds'    => $auditedIds,
            'documentLabel' => $this->documentType === 'quotation' ? 'Quotations' : 'Sale Orders',
            'routeBase'     => $this->documentType === 'quotation' ? 'inventory.quotations' : 'inventory.sale-orders',
            'statusOptions' => [
                'draft'      => 'Draft',
                'confirmed'  => 'Confirmed',
                'processing' => 'Processing',
                'shipped'    => 'Shipped',
                'partial'    => 'Partially Fulfilled',
                'fulfilled'  => 'Fulfilled',
                'completed'  => 'Completed',
                'cancelled'  => 'Cancelled',
                'returned'   => 'Returned',
            ],
        ]);
    }
}

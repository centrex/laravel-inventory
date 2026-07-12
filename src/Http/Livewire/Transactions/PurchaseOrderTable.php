<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Models\PurchaseOrder;
use Centrex\Inventory\Support\{CommercialTeamAccess, StatusBadge};
use Centrex\TallUi\Concerns\WithFilters;
use Centrex\TallUi\DataTable\{Column, Filter};
use Centrex\TallUi\Livewire\DataTable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Backs both the Purchase Orders and Requisitions index pages — $documentType
 * is passed in from PurchaseOrderIndexPage, mirroring the existing 'order' vs
 * 'requisition' split.
 */
class PurchaseOrderTable extends DataTable
{
    use WithFilters;

    public string $documentType = 'order';

    public string $defaultSortBy = 'ordered_at';

    public string $defaultSortDirection = 'desc';

    /**
     * Lazily computed, memoized per request the first time a "Due" cell is
     * rendered — batches one query for the whole page instead of one per row.
     *
     * @var array<int, float>|null
     */
    protected ?array $dueAmountsCache = null;

    public function mount(string $documentType = 'order'): void
    {
        $this->documentType = $documentType === 'requisition' ? 'requisition' : 'order';

        parent::mount();
    }

    public function columns(): array
    {
        $currency = (string) config('inventory.base_currency', 'BDT');

        return [
            Column::make('Number', 'po_number')->searchable()->sortable()
                ->view('inventory::livewire.partials.purchase-order-table.number'),
            Column::make('Date', 'ordered_at')->sortable()->format('date'),
            Column::make('Supplier', 'supplier.name')->relation('supplier')->searchable(),
            Column::make('Warehouse', 'warehouse.name')->relation('warehouse')->searchable()->hideOnMobile(),
            Column::make('Status', 'status')->badge('neutral', StatusBadge::colors()),
            Column::make('Currency', 'currency')->hideOnMobile(),
            Column::make('Total', 'total_local')->currency($currency)->sortable()->summable(),
            Column::make('Due', 'computed_due')
                ->view('inventory::livewire.partials.purchase-order-table.due')
                ->excludeFromExport(),
            Column::make('Actions')
                ->view('inventory::livewire.partials.purchase-order-table.actions'),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::select('Status', 'status', [
                'draft'     => 'Draft',
                'submitted' => 'Submitted',
                'confirmed' => 'Confirmed',
                'partial'   => 'Partially Received',
                'received'  => 'Received',
                'cancelled' => 'Cancelled',
            ]),
        ];
    }

    public function query(): Builder
    {
        $query = PurchaseOrder::query()
            ->with(['supplier', 'warehouse'])
            ->where('document_type', $this->documentType);

        CommercialTeamAccess::applyPurchaseScope($query);

        return $query;
    }

    public function renderHtmlColumn(array $column, mixed $row): string
    {
        if ($column['key'] === 'computed_due') {
            $due = $this->dueAmounts()[$row->getKey()] ?? (float) $row->total_local;

            return view('inventory::livewire.partials.purchase-order-table.due', ['due' => $due])->render();
        }

        return parent::renderHtmlColumn($column, $row);
    }

    /**
     * Resolves outstanding balance per purchase order via the linked accounting Bill
     * (matched by accounting_bill_id, source polymorphic reference, or inventory_purchase_order_id).
     * Orders without a bill yet are assumed fully due (nothing paid).
     *
     * @return array<int, float>
     */
    protected function dueAmounts(): array
    {
        if ($this->dueAmountsCache !== null) {
            return $this->dueAmountsCache;
        }

        $billClass = \Centrex\Accounting\Models\Bill::class;

        if (!class_exists($billClass)) {
            return $this->dueAmountsCache = [];
        }

        $orders = collect($this->getRows()->items());
        $poIds = $orders->pluck('id')->all();
        $billIds = $orders->pluck('accounting_bill_id')->filter()->all();

        if ($poIds === []) {
            return $this->dueAmountsCache = [];
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

        return $this->dueAmountsCache = $dueAmounts;
    }
}

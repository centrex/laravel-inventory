<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Entities;

use Centrex\Inventory\Concerns\ShowsAuditTrail;
use Centrex\Inventory\Models\{Customer, Supplier, Warehouse, WarehouseProduct};
use Centrex\Inventory\Support\{CommercialTeamAccess, CustomerClvService, CustomerExporter, InventoryEntityRegistry, SupplierExporter, WarehouseStockExporter};
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\{Layout, Url};
use Livewire\{Component, WithPagination};
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app')]
class EntityIndexPage extends Component
{
    use ShowsAuditTrail;
    use WithPagination;

    public string $entity = '';

    #[Url(as: 'search', except: '')]
    public string $search = '';

    public ?int $filterWarehouseId = null;

    public function mount(string $entity): void
    {
        InventoryEntityRegistry::definition($entity);

        $this->entity = $entity;
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilterWarehouseId(): void
    {
        $this->resetPage();
    }

    public function downloadExcel(): ?StreamedResponse
    {
        abort_unless(in_array($this->entity, ['warehouse-products', 'customers', 'suppliers'], true), 403);

        if ($this->entity === 'customers') {
            $query = Customer::query()
                ->with(['salesOwner'])
                ->withCount([
                    'saleOrders as sale_count_total' => fn ($q) => $q
                        ->where('document_type', 'order'),
                    'saleOrders as sale_count_last_month' => fn ($q) => $q
                        ->where('document_type', 'order')
                        ->where('ordered_at', '>=', now()->subMonth()),
                ])
                ->withSum([
                    'saleOrders as sale_value_last_month' => fn ($q) => $q
                        ->where('document_type', 'order')
                        ->where('ordered_at', '>=', now()->subMonth()),
                ], 'total_local')
                ->withMax([
                    'saleOrders as last_sale_date' => fn ($q) => $q
                        ->where('document_type', 'order'),
                ], 'ordered_at')
                ->orderBy('name');
            $this->applyEntityScope($query);

            if ($this->search !== '') {
                $search = $this->search;
                $query->where(function ($builder) use ($search): void {
                    $builder->where('code', 'like', '%' . $search . '%')
                        ->orWhere('name', 'like', '%' . $search . '%')
                        ->orWhere('organization_name', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%')
                        ->orWhere('phone', 'like', '%' . $search . '%');
                });
            }

            $customers = $query->get();
            $clvData = CustomerClvService::computeForCustomers($customers);

            return CustomerExporter::download($customers, 'customers-' . now()->format('Ymd-His') . '.xls', $clvData);
        }

        if ($this->entity === 'suppliers') {
            $query = Supplier::query()
                ->with(['purchaseManager'])
                ->withCount([
                    'purchaseOrders as po_count_total' => fn ($q) => $q
                        ->where('document_type', 'order'),
                    'purchaseOrders as po_count_last_month' => fn ($q) => $q
                        ->where('document_type', 'order')
                        ->where('ordered_at', '>=', now()->subMonth()),
                ])
                ->withSum([
                    'purchaseOrders as po_value_last_month' => fn ($q) => $q
                        ->where('document_type', 'order')
                        ->where('ordered_at', '>=', now()->subMonth()),
                ], 'total_local')
                ->withMax([
                    'purchaseOrders as last_po_date' => fn ($q) => $q
                        ->where('document_type', 'order'),
                ], 'ordered_at')
                ->orderBy('name');

            if ($this->search !== '') {
                $search = $this->search;
                $query->where(function ($builder) use ($search): void {
                    $builder->where('code', 'like', '%' . $search . '%')
                        ->orWhere('name', 'like', '%' . $search . '%')
                        ->orWhere('contact_email', 'like', '%' . $search . '%')
                        ->orWhere('contact_phone', 'like', '%' . $search . '%');
                });
            }

            return SupplierExporter::download($query->get(), 'suppliers-' . now()->format('Ymd-His') . '.xls');
        }

        $query = WarehouseProduct::query()->with(['warehouse', 'product', 'variant']);

        if ($this->filterWarehouseId !== null) {
            $query->where('warehouse_id', $this->filterWarehouseId);
        }

        $records = $query->orderBy('warehouse_id')->orderBy('product_id')->get();
        $suffix = $this->filterWarehouseId ? '-wh' . $this->filterWarehouseId : '';

        return WarehouseStockExporter::download($records, 'warehouse-stock' . $suffix . '-' . now()->format('Ymd-His') . '.xls');
    }

    public function delete(int $recordId): void
    {
        $model = InventoryEntityRegistry::makeModel($this->entity);
        $query = $model->newQuery();
        $this->applyEntityScope($query);

        $query->findOrFail($recordId)->delete();

        $this->dispatch('notify', type: 'success', message: 'Record deleted.');
        $this->resetPage();
    }

    public function render(): View
    {
        $definition = InventoryEntityRegistry::definition($this->entity);
        $model = InventoryEntityRegistry::makeModel($this->entity);
        $query = $model->newQuery()->latest($model->getKeyName());
        $this->applyEntityScope($query);
        $fieldDefinitions = collect($definition['form_fields'])
            ->keyBy('name')
            ->all();
        $relations = collect(InventoryEntityRegistry::indexColumns($this->entity))
            ->map(fn (string $column): ?string => $this->relationNameForColumn($column, $fieldDefinitions))
            ->filter()
            ->values()
            ->all();

        if ($this->entity === 'warehouse-products') {
            $relations = array_unique(array_merge($relations, ['product', 'variant']));
        }

        if ($relations !== []) {
            $query->with($relations);
        }

        if ($this->search !== '' && ($definition['search'] !== [] || in_array($this->entity, ['warehouse-products', 'product-prices'], true))) {
            $search = $this->search;
            $query->where(function ($builder) use ($definition, $search): void {
                foreach ($definition['search'] as $column) {
                    $builder->orWhere($column, 'like', '%' . $search . '%');
                }

                if (in_array($this->entity, ['warehouse-products', 'product-prices'], true)) {
                    $builder->orWhereHas('product', fn ($q) => $q
                        ->where('sku', 'like', '%' . $search . '%')
                        ->orWhere('name', 'like', '%' . $search . '%'));
                    $builder->orWhereHas('variant', fn ($q) => $q
                        ->where('sku', 'like', '%' . $search . '%')
                        ->orWhere('name', 'like', '%' . $search . '%'));
                }
            });
        }

        if ($this->entity === 'warehouse-products' && $this->filterWarehouseId !== null) {
            $query->where('warehouse_id', $this->filterWarehouseId);
        }

        $warehouses = $this->entity === 'warehouse-products'
            ? Warehouse::query()->orderBy('name')->get(['id', 'name'])
            : collect();

        return view('inventory::livewire.entities.index-page', [
            'definition'       => $definition,
            'columns'          => InventoryEntityRegistry::indexColumns($this->entity),
            'fieldDefinitions' => $fieldDefinitions,
            'showImageThumb'   => $this->showsImageThumb(),
            'records'          => $query->paginate(15),
            'warehouses'       => $warehouses,
        ]);
    }

    private function relationNameForColumn(string $column, array $fieldDefinitions): ?string
    {
        $field = $fieldDefinitions[$column] ?? null;

        if (!is_array($field) || empty($field['related_model']) || !str_ends_with($column, '_id')) {
            return null;
        }

        $relation = Str::camel((string) Str::beforeLast($column, '_id'));

        return method_exists(InventoryEntityRegistry::makeModel($this->entity), $relation)
            ? $relation
            : null;
    }

    private function applyEntityScope(\Illuminate\Database\Eloquent\Builder $query): void
    {
        if ($this->entity === 'customers') {
            CommercialTeamAccess::applySalesScope($query);
        }
    }

    private function showsImageThumb(): bool
    {
        return in_array($this->entity, ['products', 'product-categories', 'product-brands', 'customers', 'suppliers'], true);
    }
}

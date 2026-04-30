<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Controllers\Web;

use Centrex\Inventory\Models\{Customer, Product, Supplier};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Routing\Controller;

class AsyncSelectController extends Controller
{
    public function __invoke(Request $request, string $resource): JsonResponse
    {
        $validated = $request->validate([
            'q'            => ['nullable', 'string', 'max:255'],
            'warehouse_id' => ['nullable', 'integer'],
            'page'         => ['nullable', 'integer', 'min:1'],
        ]);

        $term = trim((string) ($validated['q'] ?? ''));
        $warehouseId = isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null;
        $page = (int) ($validated['page'] ?? 1);
        $perPage = 25;

        return response()->json(match ($resource) {
            'customers'         => $this->searchCustomers($term, $page, $perPage),
            'suppliers'         => $this->searchSuppliers($term, $page, $perPage),
            'purchase-products' => $this->searchPurchaseProducts($term, $page, $perPage),
            'sale-products'     => $this->searchSaleProducts($term, $warehouseId, $page, $perPage),
            default             => ['data' => [], 'has_more' => false],
        });
    }

    /**
     * @return array{data:array<int, array{value:int,label:string,sublabel:?string}>,has_more:bool}
     */
    private function searchCustomers(string $term, int $page, int $perPage): array
    {
        $customers = Customer::query()
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $builder) use ($term): void {
                $builder->where('organization_name', 'like', '%' . $term . '%')
                    ->orWhere('name', 'like', '%' . $term . '%')
                    ->orWhere('email', 'like', '%' . $term . '%')
                    ->orWhere('phone', 'like', '%' . $term . '%');
            }))
            ->orderBy('organization_name')
            ->orderBy('name')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage + 1)
            ->get(['id', 'name', 'organization_name', 'phone'])
            ->map(fn (Customer $customer): array => [
                'value'    => (int) $customer->id,
                'label'    => (string) ($customer->organization_name ?: $customer->name),
                'sublabel' => filled($customer->phone) ? (string) $customer->phone : null,
            ]);

        return [
            'data'     => $customers->take($perPage)->values()->all(),
            'has_more' => $customers->count() > $perPage,
        ];
    }

    /**
     * @return array{data:array<int, array{value:int,label:string}>,has_more:bool}
     */
    private function searchSuppliers(string $term, int $page, int $perPage): array
    {
        $suppliers = Supplier::query()
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $builder) use ($term): void {
                $builder->where('name', 'like', '%' . $term . '%')
                    ->orWhere('contact_email', 'like', '%' . $term . '%')
                    ->orWhere('contact_phone', 'like', '%' . $term . '%')
                    ->orWhere('code', 'like', '%' . $term . '%');
            }))
            ->orderBy('name')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage + 1)
            ->get(['id', 'name'])
            ->map(fn (Supplier $supplier): array => [
                'value' => (int) $supplier->id,
                'label' => (string) $supplier->name,
            ]);

        return [
            'data'     => $suppliers->take($perPage)->values()->all(),
            'has_more' => $suppliers->count() > $perPage,
        ];
    }

    /**
     * @return array{data:array<int, array{value:int,label:string,sublabel:?string}>,has_more:bool}
     */
    private function searchPurchaseProducts(string $term, int $page, int $perPage): array
    {
        $products = Product::query()
            ->where('is_active', true)
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $builder) use ($term): void {
                $builder->where('name', 'like', '%' . $term . '%')
                    ->orWhere('sku', 'like', '%' . $term . '%')
                    ->orWhere('barcode', 'like', '%' . $term . '%');
            }))
            ->orderBy('name')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage + 1)
            ->get(['id', 'name', 'barcode'])
            ->map(fn (Product $product): array => [
                'value'    => (int) $product->id,
                'label'    => (string) $product->name,
                'sublabel' => filled($product->barcode) ? (string) $product->barcode : null,
            ]);

        return [
            'data'     => $products->take($perPage)->values()->all(),
            'has_more' => $products->count() > $perPage,
        ];
    }

    /**
     * @return array{data:array<int, array{value:int,label:string,sublabel:?string}>,has_more:bool}
     */
    private function searchSaleProducts(string $term, ?int $warehouseId, int $page, int $perPage): array
    {
        if (!$warehouseId) {
            return ['data' => [], 'has_more' => false];
        }

        $products = Product::query()
            ->where('is_active', true)
            ->whereHas('warehouseProducts', fn (Builder $query) => $query
                ->where('warehouse_id', $warehouseId)
                ->whereRaw('(qty_on_hand - qty_reserved) > 0'))
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $builder) use ($term): void {
                $builder->where('name', 'like', '%' . $term . '%')
                    ->orWhere('sku', 'like', '%' . $term . '%')
                    ->orWhere('barcode', 'like', '%' . $term . '%');
            }))
            ->orderBy('name')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage + 1)
            ->get(['id', 'name', 'barcode'])
            ->map(fn (Product $product): array => [
                'value'    => (int) $product->id,
                'label'    => (string) $product->name,
                'sublabel' => filled($product->barcode) ? (string) $product->barcode : null,
            ]);

        return [
            'data'     => $products->take($perPage)->values()->all(),
            'has_more' => $products->count() > $perPage,
        ];
    }
}

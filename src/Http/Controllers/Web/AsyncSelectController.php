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
        ]);

        $term = trim((string) ($validated['q'] ?? ''));
        $warehouseId = isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null;

        return response()->json(match ($resource) {
            'customers'         => $this->searchCustomers($term),
            'suppliers'         => $this->searchSuppliers($term),
            'purchase-products' => $this->searchPurchaseProducts($term),
            'sale-products'     => $this->searchSaleProducts($term, $warehouseId),
            default             => [],
        });
    }

    /**
     * @return array<int, array{value:int,label:string}>
     */
    private function searchCustomers(string $term): array
    {
        return Customer::query()
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $builder) use ($term): void {
                $builder->where('name', 'like', '%' . $term . '%')
                    ->orWhere('email', 'like', '%' . $term . '%')
                    ->orWhere('phone', 'like', '%' . $term . '%');
            }))
            ->orderBy('name')
            ->limit(25)
            ->get(['id', 'name'])
            ->map(fn (Customer $customer): array => [
                'value' => (int) $customer->id,
                'label' => (string) $customer->name,
            ])
            ->all();
    }

    /**
     * @return array<int, array{value:int,label:string}>
     */
    private function searchSuppliers(string $term): array
    {
        return Supplier::query()
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $builder) use ($term): void {
                $builder->where('name', 'like', '%' . $term . '%')
                    ->orWhere('contact_email', 'like', '%' . $term . '%')
                    ->orWhere('contact_phone', 'like', '%' . $term . '%')
                    ->orWhere('code', 'like', '%' . $term . '%');
            }))
            ->orderBy('name')
            ->limit(25)
            ->get(['id', 'name'])
            ->map(fn (Supplier $supplier): array => [
                'value' => (int) $supplier->id,
                'label' => (string) $supplier->name,
            ])
            ->all();
    }

    /**
     * @return array<int, array{value:int,label:string,sublabel:?string}>
     */
    private function searchPurchaseProducts(string $term): array
    {
        return Product::query()
            ->where('is_active', true)
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $builder) use ($term): void {
                $builder->where('name', 'like', '%' . $term . '%')
                    ->orWhere('sku', 'like', '%' . $term . '%')
                    ->orWhere('barcode', 'like', '%' . $term . '%');
            }))
            ->orderBy('name')
            ->limit(25)
            ->get(['id', 'name', 'barcode'])
            ->map(fn (Product $product): array => [
                'value'    => (int) $product->id,
                'label'    => (string) $product->name,
                'sublabel' => filled($product->barcode) ? (string) $product->barcode : null,
            ])
            ->all();
    }

    /**
     * @return array<int, array{value:int,label:string,sublabel:?string}>
     */
    private function searchSaleProducts(string $term, ?int $warehouseId): array
    {
        if (!$warehouseId) {
            return [];
        }

        return Product::query()
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
            ->limit(25)
            ->get(['id', 'name', 'barcode'])
            ->map(fn (Product $product): array => [
                'value'    => (int) $product->id,
                'label'    => (string) $product->name,
                'sublabel' => filled($product->barcode) ? (string) $product->barcode : null,
            ])
            ->all();
    }
}

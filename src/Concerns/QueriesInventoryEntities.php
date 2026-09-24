<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Concerns;

use Centrex\Inventory\Enums\SaleOrderStatus;
use Centrex\Inventory\Models\{Customer, Lot, Product, ProductCategory, ProductVariant, ProductVariantAttributeType, ProductVariantAttributeValue, SaleOrder, SerialNumber, Warehouse};
use Centrex\Inventory\Support\{DayRange, InventoryEntityRegistry};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Entity lookups and light CRUD for products, variants, lots, serials, categories, customers, and sale orders — the read-side counterpart to the write-side domain traits. */
trait QueriesInventoryEntities
{
    /**
     * Default active warehouse (highest priority by is_default, then name).
     */
    public function defaultWarehouse(): ?Warehouse
    {
        return Warehouse::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->first();
    }

    /**
     * List products with optional filters.
     */
    public function listProducts(
        bool $activeOnly = true,
        bool $availableOnly = false,
        ?string $search = null,
        ?int $categoryId = null,
    ): Collection {
        return Product::query()
            ->with(['category', 'brand', 'warehouseProducts', 'prices'])
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->when($availableOnly, fn ($q) => $q->whereHas('warehouseProducts', fn ($wq) => $wq->whereRaw('qty_on_hand > qty_reserved')))
            ->when($search, fn ($q) => $q->where(fn ($sq) => $sq->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->orderBy('name')
            ->get();
    }

    /**
     * Find a single product with full relations.
     */
    public function findProduct(int $id): ?Product
    {
        return Product::query()
            ->with(['category', 'brand', 'warehouseProducts', 'prices', 'variants'])
            ->find($id);
    }

    /**
     * Look up a product or variant by barcode.
     * Returns ['product' => Product, 'variant' => ProductVariant|null] or null if not found.
     */
    public function findByBarcode(string $barcode): ?array
    {
        $variant = ProductVariant::query()
            ->with(['product.category', 'product.brand', 'product.prices', 'warehouseProducts', 'attributeValues.attributeType'])
            ->where('barcode', $barcode)
            ->first();

        if ($variant !== null) {
            return ['product' => $variant->product, 'variant' => $variant];
        }

        $product = Product::query()
            ->with(['category', 'brand', 'warehouseProducts', 'prices', 'variants'])
            ->where('barcode', $barcode)
            ->first();

        return $product !== null ? ['product' => $product, 'variant' => null] : null;
    }

    // ── Lot management ────────────────────────────────────────────────────────

    /**
     * List all lots for a product/warehouse, ordered by creation date (oldest first — FIFO order).
     */
    public function getLotsByProduct(int $productId, int $warehouseId, ?int $variantId = null): Collection
    {
        return Lot::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->when($variantId !== null, fn ($q) => $q->where('variant_id', $variantId))
            ->where('qty_on_hand', '>', 0)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Find a specific lot by its number within a product/warehouse scope.
     */
    public function findLotByNumber(int $productId, int $warehouseId, string $lotNumber, ?int $variantId = null): ?Lot
    {
        return Lot::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('lot_number', $lotNumber)
            ->when($variantId !== null, fn ($q) => $q->where('variant_id', $variantId))
            ->first();
    }

    /**
     * Return all lots expiring within the given number of days.
     */
    public function getExpiringLots(int $withinDays = 30, ?int $warehouseId = null): Collection
    {
        return Lot::when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($withinDays))
            ->where('qty_on_hand', '>', 0)
            ->orderBy('expires_at')
            ->get();
    }

    /**
     * Return all available serial numbers for a product/warehouse, optionally scoped to a lot.
     */
    public function getAvailableSerialNumbers(int $productId, int $warehouseId, ?int $lotId = null): Collection
    {
        return SerialNumber::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', SerialNumber::STATUS_AVAILABLE)
            ->when($lotId !== null, fn ($q) => $q->where('lot_id', $lotId))
            ->orderBy('id')
            ->get();
    }

    // ── Variant management ────────────────────────────────────────────────────

    /**
     * List all variants for a product, optionally filtered to active-only.
     */
    public function listVariants(int $productId, bool $activeOnly = false): Collection
    {
        return ProductVariant::query()
            ->with(['attributeValues.attributeType', 'warehouseProducts'])
            ->forProduct($productId)
            ->when($activeOnly, fn ($q) => $q->active())
            ->ordered()
            ->get();
    }

    /**
     * Find a single variant with its relations.
     */
    public function findVariant(int $variantId): ?ProductVariant
    {
        return ProductVariant::query()
            ->with(['product', 'attributeValues.attributeType', 'warehouseProducts'])
            ->find($variantId);
    }

    /**
     * Create a new variant for an existing product.
     *
     * $data keys: sku, name, barcode?, weight_kg?, sort_order?, is_active?, attributes?, meta?
     *
     * Optionally pass 'attribute_values' => [[attribute_type_id, attribute_value_id], ...]
     * to populate the normalised pivot at the same time.
     */
    public function createVariant(int $productId, array $data): ProductVariant
    {
        $product = Product::query()->findOrFail($productId);

        return DB::transaction(function () use ($product, $data): ProductVariant {
            $variant = ProductVariant::create([
                'product_id' => $product->getKey(),
                'sku'        => $data['sku'],
                'name'       => $data['name'],
                'barcode'    => $data['barcode'] ?? null,
                'weight_kg'  => $data['weight_kg'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0,
                'is_active'  => $data['is_active'] ?? true,
                'attributes' => $data['attributes'] ?? null,
                'meta'       => $data['meta'] ?? null,
            ]);

            if (!empty($data['attribute_values'])) {
                $this->syncVariantAttributeValues($variant, $data['attribute_values']);
            }

            return $variant->load(['attributeValues.attributeType']);
        });
    }

    /**
     * Update an existing variant's fields.
     *
     * Passing 'attribute_values' replaces the normalised pivot entries completely.
     */
    public function updateVariant(int $variantId, array $data): ProductVariant
    {
        $variant = ProductVariant::query()->findOrFail($variantId);

        return DB::transaction(function () use ($variant, $data): ProductVariant {
            $fillable = array_intersect_key($data, array_flip([
                'sku', 'name', 'barcode', 'weight_kg', 'sort_order', 'is_active', 'attributes', 'meta',
            ]));

            $variant->fill($fillable)->save();

            if (array_key_exists('attribute_values', $data)) {
                $this->syncVariantAttributeValues($variant, $data['attribute_values']);
            }

            return $variant->load(['attributeValues.attributeType']);
        });
    }

    /**
     * Soft-delete a variant.  Blocked if the variant has any committed
     * transaction lines (purchase/sale order items or stock movements).
     */
    public function deleteVariant(int $variantId): void
    {
        $variant = ProductVariant::query()->findOrFail($variantId);

        if ($variant->hasTransactionHistory()) {
            throw new \RuntimeException(
                "Variant [{$variantId}] cannot be deleted because it has transaction history. Deactivate it instead.",
            );
        }

        DB::transaction(function () use ($variant): void {
            $variant->warehouseProducts()->delete();
            $variant->prices()->delete();
            $variant->delete();
        });
    }

    /**
     * Deactivate a variant without deleting it (safe for variants with history).
     */
    public function deactivateVariant(int $variantId): ProductVariant
    {
        $variant = ProductVariant::query()->findOrFail($variantId);
        $variant->update(['is_active' => false]);

        return $variant;
    }

    /**
     * Duplicate an existing variant under the same (or different) product.
     * The duplicate always gets a new SKU; all other fields can be overridden.
     */
    public function duplicateVariant(int $variantId, array $overrides = []): ProductVariant
    {
        $source = ProductVariant::query()
            ->with('attributeValues')
            ->findOrFail($variantId);

        $data = array_merge([
            'sku'        => $source->sku . '-copy',
            'name'       => $source->name . ' (copy)',
            'barcode'    => null,
            'weight_kg'  => $source->weight_kg,
            'sort_order' => $source->sort_order,
            'is_active'  => false,
            'attributes' => $source->attributes,
            'meta'       => $source->meta,
        ], $overrides);

        $productId = (int) ($overrides['product_id'] ?? $source->product_id);

        return $this->createVariant($productId, $data);
    }

    // ── Attribute type / value management ────────────────────────────────────

    /**
     * Upsert an attribute type (e.g. Color, Size).
     */
    public function upsertAttributeType(string $slug, string $name, int $sortOrder = 0): ProductVariantAttributeType
    {
        return ProductVariantAttributeType::updateOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'sort_order' => $sortOrder],
        );
    }

    /**
     * Upsert an attribute value (e.g. Color → Red).
     */
    public function upsertAttributeValue(int $attributeTypeId, string $value, array $extra = []): ProductVariantAttributeValue
    {
        return ProductVariantAttributeValue::updateOrCreate(
            ['attribute_type_id' => $attributeTypeId, 'value' => $value],
            array_merge(['sort_order' => 0], $extra),
        );
    }

    /**
     * Sync the normalised pivot rows for a variant.
     *
     * $rows: [[attribute_type_id => int, attribute_value_id => int], ...]
     */
    private function syncVariantAttributeValues(ProductVariant $variant, array $rows): void
    {
        $sync = [];

        foreach ($rows as $row) {
            $sync[(int) $row['attribute_value_id']] = [
                'attribute_type_id' => (int) $row['attribute_type_id'],
            ];
        }

        $variant->attributeValues()->sync($sync);
    }

    /**
     * List product categories with active product count.
     */
    public function listProductCategories(bool $activeOnly = true): Collection
    {
        return ProductCategory::query()
            ->withCount(['products' => fn ($q) => $q->where('is_active', true)])
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Find a single product category with product count.
     */
    public function findProductCategory(int $id): ?ProductCategory
    {
        return ProductCategory::query()->withCount('products')->find($id);
    }

    /**
     * List customers with optional filters.
     *
     * Eager-loads 'media' — Customer's primary_image_url is an always-appended accessor
     * (HasPrimaryImage::getPrimaryImageUrlAttribute() -> getFirstMediaUrl()) that otherwise
     * lazy-loads the media relation once per customer, turning every call into an N+1.
     */
    public function listCustomers(bool $activeOnly = false, ?string $search = null): Collection
    {
        return Customer::query()
            ->with('media')
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->when($search, fn ($q) => $q->where(fn ($sq) => $sq->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
            ->orderBy('name')
            ->get();
    }

    /**
     * Find a single customer by ID.
     */
    public function findCustomer(int $id): ?Customer
    {
        return Customer::query()->find($id);
    }

    /**
     * Find the customer linked to a morphable model (e.g. a User).
     */
    public function findCustomerForModel(string $morphClass, int $morphId): ?Customer
    {
        return Customer::query()
            ->where('modelable_type', $morphClass)
            ->where('modelable_id', $morphId)
            ->first();
    }

    /**
     * Create a customer with auto-generated code.
     */
    public function createCustomer(array $data): Customer
    {
        if (empty($data['code'])) {
            $data['code'] = InventoryEntityRegistry::autoGeneratedCode('customers');
        }

        $data += [
            'credit_limit_amount' => 0,
            'is_active'           => true,
            'currency'            => config('inventory.base_currency', 'BDT'),
        ];

        return Customer::query()->create($data);
    }

    /**
     * Update a customer by ID.
     */
    public function updateCustomer(int $id, array $data): Customer
    {
        $customer = Customer::query()->findOrFail($id);
        $customer->update($data);

        return $customer->fresh();
    }

    /**
     * Delete a customer by ID.
     */
    public function deleteCustomer(int $id): void
    {
        Customer::query()->findOrFail($id)->delete();
    }

    /**
     * List sale orders with optional date/status filters.
     */
    public function listSaleOrders(
        ?string $status = null,
        ?string $from = null,
        ?string $to = null,
        bool $excludeTerminal = false,
    ): Collection {
        return SaleOrder::query()
            ->with(['customer', 'warehouse', 'items.product'])
            ->where('document_type', 'sale')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($excludeTerminal, fn ($q) => $q->whereNotIn('status', [SaleOrderStatus::FULFILLED->value, SaleOrderStatus::COMPLETED->value, SaleOrderStatus::CANCELLED->value, SaleOrderStatus::RETURNED->value]))
            ->when($from, fn ($q) => $q->where('ordered_at', '>=', DayRange::from($from)))
            ->when($to, fn ($q) => $q->where('ordered_at', '<', DayRange::until($to)))
            ->latest('ordered_at')
            ->get();
    }

    /**
     * Find a single sale order with relations.
     */
    public function findSaleOrder(int $id): ?SaleOrder
    {
        return SaleOrder::query()
            ->with(['customer', 'warehouse', 'items.product'])
            ->where('document_type', 'sale')
            ->find($id);
    }

    /**
     * Oldest pending sale order date (draft/confirmed/processing/partial).
     */
    public function oldestPendingOrderDate(): ?string
    {
        $order = SaleOrder::query()
            ->where('document_type', 'sale')
            ->whereIn('status', [
                SaleOrderStatus::DRAFT->value,
                SaleOrderStatus::CONFIRMED->value,
                SaleOrderStatus::PROCESSING->value,
                SaleOrderStatus::PARTIAL->value,
            ])
            ->oldest('ordered_at')
            ->first();

        return $order?->ordered_at?->toDateString();
    }

    /**
     * Estimate shipping cost for a list of items (product_id + qty).
     */
    public function estimateShipping(array $items): array
    {
        $products = Product::query()
            ->whereIn('id', collect($items)->pluck('product_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $rate = (float) config('inventory.shipping_rate_per_kg', env('ERP_APP_SHIPPING_RATE_PER_KG', 120));

        $totalWeightKg = collect($items)->sum(function (array $item) use ($products): float {
            $product = $products->get((int) ($item['product_id'] ?? 0));
            $qty = (float) ($item['qty'] ?? $item['quantity'] ?? 0);

            return (float) ($product?->weight_kg ?? 0) * $qty;
        });

        return [
            'total_weight_kg' => round($totalWeightKg, 4),
            'rate_per_kg'     => $rate,
            'shipping_cost'   => $totalWeightKg > 0 ? round($totalWeightKg * $rate, 2) : 0.0,
        ];
    }
}

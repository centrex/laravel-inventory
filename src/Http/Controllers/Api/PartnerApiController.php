<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Controllers\Api;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Partner, Product, Warehouse, WarehouseProduct};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Routing\Controller;

/**
 * Partner-scoped API: authenticated via X-Partner-Key header.
 * Partners can query stock/prices and create sale orders on behalf of their customer.
 */
class PartnerApiController extends Controller
{
    public function __construct(
        private readonly Inventory $inventory,
    ) {}

    // -------------------------------------------------------------------------
    // Authentication helper
    // -------------------------------------------------------------------------

    private function resolvePartner(Request $request): Partner
    {
        $apiKey = $request->header('X-Partner-Key') ?? $request->input('api_key');

        if (!$apiKey) {
            abort(401, 'Missing API key.');
        }

        $partner = Partner::where('api_key', $apiKey)->where('is_active', true)->first();

        if (!$partner) {
            abort(401, 'Invalid or inactive API key.');
        }

        return $partner;
    }

    // -------------------------------------------------------------------------
    // Stock endpoints
    // -------------------------------------------------------------------------

    public function stockLevels(Request $request): JsonResponse
    {
        $partner = $this->resolvePartner($request);

        if (!$partner->can_view_stock) {
            abort(403, 'This partner does not have stock visibility.');
        }

        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'integer'],
            'sku'          => ['nullable', 'string'],
            'search'       => ['nullable', 'string', 'max:80'],
            'per_page'     => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $warehouseId = $validated['warehouse_id'] ?? $partner->default_warehouse_id;

        $query = WarehouseProduct::with(['product', 'warehouse'])
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($partner->allowed_warehouse_ids, fn ($q) => $q->whereIn('warehouse_id', $partner->allowed_warehouse_ids))
            ->when($partner->allowed_product_ids, fn ($q) => $q->whereIn('product_id', $partner->allowed_product_ids))
            ->when($validated['sku'] ?? null, fn ($q, $sku) => $q->whereHas('product', fn ($p) => $p->where('sku', $sku)))
            ->when($validated['search'] ?? null, fn ($q, $s) => $q->whereHas('product', fn ($p) => $p->where('name', 'like', "%{$s}%")));

        $results = $query->paginate($validated['per_page'] ?? 50);

        return response()->json($results->through(fn (WarehouseProduct $wp) => [
            'product_id'   => $wp->product_id,
            'sku'          => $wp->product?->sku,
            'product_name' => $wp->product?->name,
            'warehouse_id' => $wp->warehouse_id,
            'qty_available' => $wp->qtyAvailable(),
            'in_stock'     => $wp->qtyAvailable() > 0,
        ]));
    }

    // -------------------------------------------------------------------------
    // Price endpoints
    // -------------------------------------------------------------------------

    public function priceSheet(Request $request): JsonResponse
    {
        $partner = $this->resolvePartner($request);

        if (!$partner->can_view_prices) {
            abort(403, 'This partner does not have price visibility.');
        }

        $validated = $request->validate([
            'product_id'   => ['required', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
        ]);

        $warehouseId = $validated['warehouse_id'] ?? $partner->default_warehouse_id;

        if (!$partner->canAccessProduct($validated['product_id'])) {
            abort(403, 'Access to this product is not permitted.');
        }

        $sheet = $this->inventory->getPriceSheet($validated['product_id'], $warehouseId);

        // Only expose the partner's default tier (or all if they have full price access)
        return response()->json([
            'product_id'   => $validated['product_id'],
            'warehouse_id' => $warehouseId,
            'price_tier'   => $partner->default_price_tier,
            'prices'       => $sheet->where('tier_code', $partner->default_price_tier)->values(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Order endpoints
    // -------------------------------------------------------------------------

    public function createOrder(Request $request): JsonResponse
    {
        $partner = $this->resolvePartner($request);

        if (!$partner->can_create_orders) {
            abort(403, 'This partner is not authorised to create orders.');
        }

        $validated = $request->validate([
            'warehouse_id'  => ['nullable', 'integer'],
            'currency'      => ['nullable', 'string', 'size:3'],
            'notes'         => ['nullable', 'string'],
            'items'         => ['required', 'array', 'min:1'],
            'items.*.sku'   => ['required_without:items.*.product_id', 'string'],
            'items.*.product_id' => ['required_without:items.*.sku', 'integer'],
            'items.*.qty_ordered' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price_local' => ['nullable', 'numeric', 'gt:0'],
            'items.*.discount_pct'     => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $warehouseId = $validated['warehouse_id'] ?? $partner->default_warehouse_id;

        if ($warehouseId && !$partner->canAccessWarehouse($warehouseId)) {
            abort(403, 'Access to this warehouse is not permitted.');
        }

        // Resolve product_id from SKU if needed
        $resolvedItems = [];

        foreach ($validated['items'] as $item) {
            $productId = $item['product_id'] ?? null;

            if (!$productId && isset($item['sku'])) {
                $product = Product::where('sku', $item['sku'])->first();

                if (!$product) {
                    abort(422, "Product with SKU [{$item['sku']}] not found.");
                }

                $productId = $product->id;
            }

            if (!$partner->canAccessProduct($productId)) {
                abort(403, "Access to product [{$productId}] is not permitted.");
            }

            $resolvedItems[] = array_merge($item, [
                'product_id'       => $productId,
                'price_tier_code'  => $partner->default_price_tier,
            ]);
        }

        $so = $this->inventory->createSaleOrder([
            'warehouse_id'    => $warehouseId,
            'customer_id'     => $partner->customer_id,
            'price_tier_code' => $partner->default_price_tier,
            'currency'        => $validated['currency'] ?? config('inventory.base_currency', 'BDT'),
            'notes'           => ($validated['notes'] ?? '') . ' [partner:' . $partner->id . ']',
            'items'           => $resolvedItems,
        ]);

        return response()->json($so->load('items'), 201);
    }

    public function getOrder(Request $request, int $soId): JsonResponse
    {
        $partner = $this->resolvePartner($request);

        $so = \Centrex\Inventory\Models\SaleOrder::with('items.product')
            ->where('id', $soId)
            ->whereRaw("notes LIKE ?", ["%[partner:{$partner->id}]%"])
            ->firstOrFail();

        return response()->json($so);
    }
}

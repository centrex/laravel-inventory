<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Concerns;

use Centrex\Inventory\Enums\PriceTierCode;
use Centrex\Inventory\Exceptions\PriceNotFoundException;
use Centrex\Inventory\Models\ProductPrice;
use Illuminate\Support\Collection;

trait ManagesPricing
{
    // -------------------------------------------------------------------------
    // Price Tiers
    // -------------------------------------------------------------------------

    /**
     * No-op — price tiers are defined by the {@see PriceTierCode} enum and require no database seeding.
     *
     * @deprecated Kept only to avoid breaking callers that relied on the previous table-backed implementation.
     */
    public function seedPriceTiers(): void
    {
        // Price tiers are enum-backed and no longer persisted in a dedicated table.
    }

    // -------------------------------------------------------------------------
    // Price Management
    // -------------------------------------------------------------------------

    /**
     * Set or update a sell price for a product + tier (optionally scoped to a warehouse).
     * warehouse_id = null means global/default.
     */
    public function setPrice(int $productId, string $tierCode, float $priceAmount, ?int $warehouseId = null, array $options = []): ProductPrice
    {
        $tierCode = $this->normalizePriceTierCode($tierCode);
        $variantId = $this->normalizeVariantId($options['variant_id'] ?? null, $productId);
        $isDamaged = (bool) ($options['is_damaged'] ?? false);

        $data = [
            'variant_id'      => $variantId,
            'price_tier_code' => $tierCode,
            'price_amount'    => $priceAmount,
            'cost_price'      => $options['cost_price'] ?? null,
            'moq'             => $options['moq'] ?? 1,
            'price_local'     => $options['price_local'] ?? null,
            'currency'        => $options['currency'] ?? null,
            'effective_from'  => $options['effective_from'] ?? null,
            'effective_to'    => $options['effective_to'] ?? null,
            'is_active'       => $options['is_active'] ?? true,
            'is_damaged'      => $isDamaged,
        ];

        return ProductPrice::updateOrCreate(
            [
                'product_id'      => $productId,
                'variant_id'      => $variantId,
                'price_tier_code' => $tierCode,
                'warehouse_id'    => $warehouseId,
                'effective_from'  => $data['effective_from'],
                'is_damaged'      => $isDamaged,
            ],
            $data,
        );
    }

    /**
     * Resolve the effective sell price for a product + tier at a given warehouse.
     * Priority: warehouse-specific active price → global active price.
     * Pass $damaged=true to prefer damaged-condition prices; falls back to regular price if none found.
     */
    public function resolvePrice(int $productId, string $tierCode, int $warehouseId, ?string $date = null, ?int $variantId = null, bool $damaged = false): ProductPrice
    {
        $tierCode = $this->normalizePriceTierCode($tierCode);
        $date ??= now()->toDateString();
        $variantId = $this->normalizeVariantId($variantId, $productId);

        $makeBase = fn (bool $isDamaged) => ProductPrice::where('product_id', $productId)
            ->where('price_tier_code', $tierCode)
            ->where('is_active', true)
            ->where('is_damaged', $isDamaged)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));

        $lookup = function (bool $isDamaged) use ($makeBase, $variantId, $warehouseId): ?ProductPrice {
            $base = $makeBase($isDamaged);
            $price = null;

            if ($variantId !== null) {
                $price = (clone $base)->where('variant_id', $variantId)->where('warehouse_id', $warehouseId)->latest()->first();
                $price ??= (clone $base)->where('variant_id', $variantId)->whereNull('warehouse_id')->latest()->first();
            }

            $price ??= (clone $base)->whereNull('variant_id')->where('warehouse_id', $warehouseId)->latest()->first();
            $price ??= (clone $base)->whereNull('variant_id')->whereNull('warehouse_id')->latest()->first();

            return $price;
        };

        $price = $damaged ? ($lookup(true) ?? $lookup(false)) : $lookup(false);

        if (!$price) {
            if (config('inventory.price_not_found_throws', true)) {
                throw new PriceNotFoundException("No price found for product [{$productId}], tier [{$tierCode}], warehouse [{$warehouseId}].");
            }

            return new ProductPrice(['price_amount' => 0, 'price_local' => 0]);
        }

        return $price;
    }

    /**
     * Single-query prefetch for pickPrice(): every active, currently-effective ProductPrice row
     * for the given products, grouped by product_id. Deliberately not filtered by tier/variant/
     * warehouse here — those vary per line item and are cheap to filter from an in-memory
     * collection, whereas issuing one query per combination is exactly the N+1 this exists to
     * avoid.
     *
     * @param  Collection<int, int>  $productIds
     * @return Collection<int, Collection<int, ProductPrice>>
     */
    private function loadPriceCandidatesForProducts(Collection $productIds): Collection
    {
        if ($productIds->isEmpty()) {
            return collect();
        }

        $date = now()->toDateString();

        return ProductPrice::query()
            ->whereIn('product_id', $productIds)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->get()
            ->groupBy('product_id');
    }

    /**
     * In-memory equivalent of resolvePrice(), scored against a prefetched candidate collection
     * for one product (see loadPriceCandidatesForProducts()) instead of querying. Fallback order
     * and damaged-price handling must stay identical to resolvePrice() — the two are exercised
     * against the same fixtures in tests/Feature/SaleOrderPricingTest.php.
     */
    private function pickPrice(Collection $candidates, int $productId, string $tierCode, int $warehouseId, ?int $variantId, bool $damaged): ProductPrice
    {
        $tierCode = $this->normalizePriceTierCode($tierCode);
        $variantId = $this->normalizeVariantId($variantId, $productId);

        $scoped = fn (bool $isDamaged) => $candidates
            ->where('price_tier_code', $tierCode)
            ->where('is_damaged', $isDamaged);

        $pick = fn (Collection $pool, ?int $variantMatch, ?int $warehouseMatch): ?ProductPrice => $pool
            ->when($variantMatch === null, fn (Collection $c) => $c->whereNull('variant_id'), fn (Collection $c) => $c->where('variant_id', $variantMatch))
            ->when($warehouseMatch === null, fn (Collection $c) => $c->whereNull('warehouse_id'), fn (Collection $c) => $c->where('warehouse_id', $warehouseMatch))
            ->sortByDesc('created_at')
            ->first();

        $lookup = function (bool $isDamaged) use ($scoped, $pick, $variantId, $warehouseId): ?ProductPrice {
            $pool = $scoped($isDamaged);
            $price = null;

            if ($variantId !== null) {
                $price = $pick($pool, $variantId, $warehouseId);
                $price ??= $pick($pool, $variantId, null);
            }

            $price ??= $pick($pool, null, $warehouseId);
            $price ??= $pick($pool, null, null);

            return $price;
        };

        $price = $damaged ? ($lookup(true) ?? $lookup(false)) : $lookup(false);

        if (!$price) {
            if (config('inventory.price_not_found_throws', true)) {
                throw new PriceNotFoundException("No price found for product [{$productId}], tier [{$tierCode}], warehouse [{$warehouseId}].");
            }

            return new ProductPrice(['price_amount' => 0, 'price_local' => 0]);
        }

        return $price;
    }

    /**
     * Get all tier prices for a product at a warehouse (global fallback per tier).
     */
    public function getPriceSheet(int $productId, int $warehouseId, ?string $date = null, ?int $variantId = null): Collection
    {
        return collect(PriceTierCode::ordered())
            ->map(function (PriceTierCode $tier) use ($productId, $warehouseId, $date, $variantId) {
                try {
                    $price = $this->resolvePrice($productId, $tier->value, $warehouseId, $date, $variantId);
                } catch (PriceNotFoundException) {
                    $price = null;
                }

                return [
                    'tier_code'    => $tier->value,
                    'tier_name'    => $tier->label(),
                    'price_amount' => $price?->price_amount,
                    'price_local'  => $price?->price_local,
                    'currency'     => $price?->currency,
                    'source'       => $price ? ($price->warehouse_id ? 'warehouse' : 'global') : null,
                ];
            });
    }

    // -------------------------------------------------------------------------
}

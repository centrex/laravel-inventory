<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Concerns;

use Centrex\Inventory\Models\Partner;
use Illuminate\Support\Collection;

trait ManagesPartners
{
    /**
     * Create a new API partner (dropshipper / e-commerce / B2B / marketplace).
     * Returns the partner with the generated api_key (shown only once).
     */
    public function createPartner(array $data): Partner
    {
        return Partner::create([
            'name'                  => $data['name'],
            'type'                  => $data['type'] ?? 'dropshipper',
            'api_key'               => Partner::generateApiKey(),
            'customer_id'           => $data['customer_id'] ?? null,
            'default_warehouse_id'  => $data['default_warehouse_id'] ?? null,
            'default_price_tier'    => $data['default_price_tier'] ?? 'B2B_WHOLESALE',
            'can_view_stock'        => $data['can_view_stock'] ?? true,
            'can_view_prices'       => $data['can_view_prices'] ?? true,
            'can_create_orders'     => $data['can_create_orders'] ?? true,
            'is_active'             => $data['is_active'] ?? true,
            'allowed_warehouse_ids' => $data['allowed_warehouse_ids'] ?? null,
            'allowed_product_ids'   => $data['allowed_product_ids'] ?? null,
        ]);
    }

    public function updatePartner(int $partnerId, array $data): Partner
    {
        $partner = Partner::findOrFail($partnerId);
        $partner->update($data);

        return $partner->refresh();
    }

    /**
     * Rotate the API key for a partner. Only the hash is persisted — the returned model's
     * getPlainApiKey() holds the new plaintext key for exactly this one response.
     */
    public function rotatePartnerApiKey(int $partnerId): Partner
    {
        $partner = Partner::findOrFail($partnerId);
        $partner->update(['api_key' => Partner::generateApiKey()]);

        return $partner;
    }

    public function listPartners(bool $activeOnly = true): Collection
    {
        return Partner::when($activeOnly, fn ($q) => $q->where('is_active', true))->get();
    }
}

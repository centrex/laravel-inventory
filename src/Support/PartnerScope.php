<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Support;

use Centrex\Inventory\Models\{Partner, SaleOrder};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User;

/**
 * Scoping helper for dropshipper / ecom partner back-office access.
 *
 * Usage in a Livewire component or controller:
 *
 *   $partner = PartnerScope::forUser(auth()->user());
 *   $orders  = PartnerScope::saleOrdersQuery($partner)->paginate(20);
 */
class PartnerScope
{
    /**
     * Resolve the Partner record for the currently authenticated user.
     * Returns null if the user has no linked partner record.
     */
    public static function forUser(User $user): ?Partner
    {
        // Support lookup by polymorphic customer morph (customer->modelable_type / modelable_id)
        return Partner::query()
            ->whereHas('customer', fn ($q) => $q
                ->where('modelable_type', get_class($user))
                ->where('modelable_id', $user->getKey()))
            ->where('is_active', true)
            ->first();
    }

    /**
     * Return a scoped SaleOrder query restricted to the partner's customer.
     * Call ->paginate() or ->get() on the result.
     */
    public static function saleOrdersQuery(Partner $partner): Builder
    {
        return SaleOrder::with('items.product')
            ->where('customer_id', $partner->customer_id)
            ->when(
                $partner->allowed_warehouse_ids,
                fn ($q) => $q->whereIn('warehouse_id', $partner->allowed_warehouse_ids),
            )
            ->latest();
    }

    /**
     * Verify a partner can access a specific sale order.
     */
    public static function canViewOrder(Partner $partner, SaleOrder $order): bool
    {
        if ($order->customer_id !== $partner->customer_id) {
            return false;
        }

        return !($partner->allowed_warehouse_ids !== null && !in_array($order->warehouse_id, $partner->allowed_warehouse_ids, true));
    }

    /**
     * Return the effective price tier for a partner user.
     */
    public static function priceTier(Partner $partner): string
    {
        return $partner->default_price_tier;
    }
}

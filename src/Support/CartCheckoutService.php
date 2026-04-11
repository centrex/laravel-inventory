<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Support;

use Centrex\Inventory\Enums\PriceTierCode;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\SaleOrder;

class CartCheckoutService
{
    public function __construct(
        private readonly Inventory $inventory,
        private readonly ErpIntegration $erpIntegration,
    ) {}

    public function checkout(array $payload, string $channel): SaleOrder
    {
        if (!class_exists(\Centrex\Cart\Cart::class)) {
            throw new \RuntimeException('centrex/laravel-cart is required for channel checkout flows.');
        }

        $cart = app(\Centrex\Cart\Cart::class)->instance(
            (string) ($payload['cart_instance'] ?? config("laravel-cart.instances.{$channel}") ?? $channel),
        );

        if ($cart->isEmpty()) {
            throw new \InvalidArgumentException("The [{$channel}] cart is empty.");
        }

        $saleOrder = $this->inventory->createSaleOrder([
            'warehouse_id'    => (int) $payload['warehouse_id'],
            'customer_id'     => $payload['customer_id'] ?? null,
            'price_tier_code' => $payload['price_tier_code'] ?? PriceTierCode::RETAIL->value,
            'currency'        => strtoupper((string) ($payload['currency'] ?? config('inventory.base_currency', 'BDT'))),
            'exchange_rate'   => $payload['exchange_rate'] ?? null,
            'tax_local'       => (float) ($payload['tax_local'] ?? 0),
            'discount_local'  => (float) ($payload['discount_local'] ?? 0),
            'notes'           => $payload['notes'] ?? null,
            'created_by'      => $payload['created_by'] ?? null,
            'items'           => $cart->content()->map(function ($item): array {
                return [
                    'product_id'       => (int) $item->id,
                    'qty_ordered'      => (float) $item->qty,
                    'unit_price_local' => (float) $item->price,
                    'discount_pct'     => (float) ($item->options['discount_pct'] ?? 0),
                    'notes'            => $item->options['notes'] ?? null,
                ];
            })->values()->all(),
        ]);

        $this->attachCheckoutMetadata($saleOrder, [
            'channel'       => $channel,
            'cart_instance' => $cart->getInstanceName(),
            'cart_lines'    => $cart->lines(),
            'cart_count'    => $cart->count(),
            'cart_subtotal' => $cart->subtotal(),
            'cart_tax'      => $cart->tax(),
            'cart_total'    => $cart->total(),
            'source'        => $payload['source'] ?? null,
            'context'       => $payload['context'] ?? [],
        ]);

        if ((bool) ($payload['confirm'] ?? true)) {
            $saleOrder = $this->inventory->confirmSaleOrder($saleOrder->id);
        }

        if ((bool) ($payload['reserve'] ?? ($channel === 'pos'))) {
            $saleOrder = $this->inventory->reserveStock($saleOrder->id);
        }

        if ((bool) ($payload['fulfill'] ?? ($channel === 'pos'))) {
            $saleOrder = $this->inventory->fulfillSaleOrder($saleOrder->id);
        }

        if ((bool) ($payload['clear_cart'] ?? true)) {
            $cart->clear();
        }

        return $saleOrder->fresh(['items']);
    }

    private function attachCheckoutMetadata(SaleOrder $saleOrder, array $data): void
    {
        if (!class_exists(\Centrex\ModelData\Data::class)) {
            return;
        }

        \Centrex\ModelData\Data::putForModel($saleOrder, $data);
    }
}

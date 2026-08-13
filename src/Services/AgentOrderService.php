<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Services;

use Centrex\Inventory\Enums\{OrderRole, SaleOrderStatus};
use Centrex\Inventory\Exceptions\PriceNotFoundException;
use Centrex\Inventory\Facades\Inventory;
use Centrex\Inventory\Models\{Agent, Customer, Product, SaleOrder};
use Centrex\Inventory\Support\ErpIntegration;
use Illuminate\Support\Facades\DB;

class AgentOrderService
{
    /**
     * Create a paired B2C + B2B sale order for an agent-driven sale.
     *
     * The B2C order is placed for the end customer at retail price.
     * The B2B order is placed for the agent at wholesale price and
     * is the one that controls stock reservation and fulfillment.
     *
     * @param  array{
     *     warehouse_id: int,
     *     customer_id: int,
     *     currency?: string,
     *     notes?: string,
     *     ordered_at?: string,
     *     items: array<int, array{product_id: int, variant_id?: int|null, qty_ordered: float, unit_price_local?: float}>
     * } $b2cData
     * @param  int  $agentId  The inv_agents.id of the sales agent
     * @return array{b2c_order: SaleOrder, b2b_order: SaleOrder}
     */
    public function createPairedOrders(array $b2cData, int $agentId): array
    {
        $agent = Agent::findOrFail($agentId);
        // The B2B order is placed for the agent's linked inv_customers record
        $agentCustomerId = (int) ($agent->customer_id ?? throw new \InvalidArgumentException(
            "Agent [{$agent->name}] has no linked customer record. Set agent->customer_id before creating orders.",
        ));

        $warehouseId = (int) $b2cData['warehouse_id'];
        $b2cTier = config('inventory.agent.default_b2c_tier', 'b2c_retail');
        $b2bTier = $agent->price_tier_code ?: config('inventory.agent.default_b2b_tier', 'b2b_wholesale');

        // Resolve B2C and B2B prices for every item up front
        $resolvedItems = $this->resolveItemPrices(
            $b2cData['items'],
            $warehouseId,
            $b2cTier,
            $b2bTier,
        );

        return DB::transaction(function () use ($b2cData, $agentId, $agentCustomerId, $warehouseId, $b2cTier, $b2bTier, $resolvedItems): array {
            // 1. Create B2C sale order (end customer, retail price)
            $b2cOrder = Inventory::createSaleOrder([
                'warehouse_id'    => $warehouseId,
                'customer_id'     => (int) $b2cData['customer_id'],
                'price_tier_code' => $b2cTier,
                'currency'        => $b2cData['currency'] ?? 'BDT',
                'ordered_at'      => $b2cData['ordered_at'] ?? now(),
                'notes'           => $b2cData['notes'] ?? null,
                'items'           => collect($resolvedItems)->map(fn ($i) => [
                    'product_id'       => $i['product_id'],
                    'variant_id'       => $i['variant_id'] ?? null,
                    'qty_ordered'      => $i['qty_ordered'],
                    'unit_price_local' => $i['b2c_price'],
                ])->toArray(),
            ]);

            // 2. Create B2B sale order (agent customer, wholesale price)
            $b2bOrder = Inventory::createSaleOrder([
                'warehouse_id'    => $warehouseId,
                'customer_id'     => $agentCustomerId,
                'price_tier_code' => $b2bTier,
                'currency'        => $b2cData['currency'] ?? 'BDT',
                'ordered_at'      => $b2cData['ordered_at'] ?? now(),
                'notes'           => $b2cData['notes'] ?? null,
                'items'           => collect($resolvedItems)->map(fn ($i) => [
                    'product_id'       => $i['product_id'],
                    'variant_id'       => $i['variant_id'] ?? null,
                    'qty_ordered'      => $i['qty_ordered'],
                    'unit_price_local' => $i['b2b_price'],
                ])->toArray(),
            ]);

            // 3. Stamp agent metadata on both orders
            $soTable = $b2cOrder->getTable();

            DB::table($soTable)->where('id', $b2cOrder->id)->update([
                'order_role'           => OrderRole::AGENT_B2C->value,
                'paired_sale_order_id' => $b2bOrder->id,
                'agent_customer_id'    => $agentCustomerId,
            ]);

            DB::table($soTable)->where('id', $b2bOrder->id)->update([
                'order_role'           => OrderRole::AGENT_B2B->value,
                'paired_sale_order_id' => $b2cOrder->id,
                'agent_customer_id'    => $agentCustomerId,
            ]);

            $result = [
                'b2c_order' => $b2cOrder->fresh() ?? $b2cOrder,
                'b2b_order' => $b2bOrder->fresh() ?? $b2bOrder,
            ];

            // Auto-generate accounting invoices for both sides when accounting integration is enabled
            $this->maybePostAccountingInvoices(
                $result['b2c_order'],
                $result['b2b_order'],
                $b2cData,
                $agentId,
                $agentCustomerId,
                $resolvedItems,
            );

            return $result;
        });
    }

    /**
     * Confirm the B2B order and reserve stock.
     *
     * Call this after the agent/manager approves the paired order set. The paired B2C
     * order isn't touched directly here — {@see \Centrex\Inventory\Observers\PairedOrderObserver}
     * mirrors every B2B status transition (confirmed, then processing once stock is
     * reserved) onto it automatically.
     *
     * @return array{b2c_order: SaleOrder, b2b_order: SaleOrder}
     */
    public function confirmAndReserve(SaleOrder $b2bOrder): array
    {
        $this->assertRole($b2bOrder, OrderRole::AGENT_B2B);

        Inventory::confirmSaleOrder($b2bOrder->id);
        Inventory::reserveStock($b2bOrder->id);

        // A B2B order created via createPairedOrders() always has a paired B2C order —
        // a missing one here means the pairing was broken by something else, which is
        // worth failing loudly on rather than silently returning a null order.
        $b2cOrder = SaleOrder::findOrFail($b2bOrder->paired_sale_order_id);

        return [
            'b2c_order' => $b2cOrder,
            'b2b_order' => $b2bOrder->fresh() ?? $b2bOrder,
        ];
    }

    /**
     * Cancel both orders in the pair.
     */
    public function cancelPair(SaleOrder $order): void
    {
        $this->assertRole($order, OrderRole::AGENT_B2C, OrderRole::AGENT_B2B);

        Inventory::cancelSaleOrder($order->id);

        $pairedId = $order->paired_sale_order_id;

        if ($pairedId) {
            $paired = SaleOrder::find($pairedId);

            if ($paired && !in_array($paired->status, [SaleOrderStatus::CANCELLED, SaleOrderStatus::RETURNED], true)) {
                Inventory::cancelSaleOrder($paired->id);
            }
        }
    }

    /**
     * Calculate the agent's margin for a B2C/B2B order pair.
     *
     * @return array{b2c_total: float, b2b_total: float, margin: float, margin_pct: float}
     */
    public function agentMargin(SaleOrder $b2cOrder): array
    {
        $this->assertRole($b2cOrder, OrderRole::AGENT_B2C);

        $b2c = (float) $b2cOrder->total_amount;
        $b2b = 0.0;

        $paired = SaleOrder::find($b2cOrder->paired_sale_order_id);

        if ($paired) {
            $b2b = (float) $paired->total_amount;
        }

        $margin = $b2c - $b2b;
        $marginPct = $b2c > 0 ? round($margin / $b2c * 100, 2) : 0.0;

        return [
            'b2c_total'  => $b2c,
            'b2b_total'  => $b2b,
            'margin'     => $margin,
            'margin_pct' => $marginPct,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Post two accounting invoices for an agent order pair.
     *
     * B2C invoice  — billed to the end customer at retail price.
     *   source_type = 'agent_b2c' — appears in the normal accounting invoice list.
     *
     * B2B invoice  — billed to the agent at wholesale price.
     *   source_type = 'agent_b2b', source_id = inv_agents.id — HIDDEN from the main
     *   accounting invoice list; visible only at /inventory/agents/{id}/invoices.
     *
     * Both sides are non-fatal: a failure never rolls back the sale orders.
     */
    private function maybePostAccountingInvoices(
        SaleOrder $b2cOrder,
        SaleOrder $b2bOrder,
        array $b2cData,
        int $agentId,
        int $agentCustomerId,
        array $resolvedItems,
    ): void {
        if (!app(ErpIntegration::class)->enabled()) {
            return;
        }

        $currency = $b2cData['currency'] ?? 'BDT';
        $dueDays = (int) config('inventory.agent.invoice_due_days', 30);
        $soTable = $b2cOrder->getTable();

        // ── Invoice 1: B2C — end customer, retail price ───────────────────────
        try {
            $b2cCustomer = Customer::find((int) $b2cData['customer_id']);

            if ($b2cCustomer?->accounting_customer_id) {
                $subtotal = (float) collect($resolvedItems)->sum(fn ($i) => $i['qty_ordered'] * $i['b2c_price']);

                $b2cInvoice = \Centrex\Accounting\Models\Invoice::create([
                    'customer_id'             => $b2cCustomer->accounting_customer_id,
                    'invoice_date'            => now()->toDateString(),
                    'due_date'                => now()->addDays($dueDays)->toDateString(),
                    'currency'                => $currency,
                    'subtotal'                => $subtotal,
                    'tax_amount'              => 0,
                    'total'                   => $subtotal,
                    'notes'                   => $b2cOrder->so_number,
                    'source_type'             => 'agent_b2c',
                    'source_id'               => $b2cOrder->id,
                    'source_reference'        => $b2cOrder->so_number,
                    'inventory_sale_order_id' => $b2cOrder->id,
                ]);

                $b2cInvoice->items()->createMany(
                    collect($resolvedItems)->map(fn ($i) => [
                        'description' => $i['product_name'] ?? ('Product #' . $i['product_id']),
                        'quantity'    => $i['qty_ordered'],
                        'unit_price'  => $i['b2c_price'],
                        'amount'      => $i['qty_ordered'] * $i['b2c_price'],
                    ])->all(),
                );

                \Centrex\Accounting\Facades\Accounting::postInvoice($b2cInvoice);

                DB::table($soTable)->where('id', $b2cOrder->id)
                    ->update(['accounting_invoice_id' => $b2cInvoice->id]);
            }
        } catch (\Throwable) {
            // non-fatal
        }

        // ── Invoice 2: B2B — agent wholesale invoice (hidden from main list) ──
        try {
            $agentCustomer = Customer::find($agentCustomerId);

            if ($agentCustomer?->accounting_customer_id) {
                $subtotal = (float) collect($resolvedItems)->sum(fn ($i) => $i['qty_ordered'] * $i['b2b_price']);

                $b2bInvoice = \Centrex\Accounting\Models\Invoice::create([
                    'customer_id'             => $agentCustomer->accounting_customer_id,
                    'invoice_date'            => now()->toDateString(),
                    'due_date'                => now()->addDays($dueDays)->toDateString(),
                    'currency'                => $currency,
                    'subtotal'                => $subtotal,
                    'tax_amount'              => 0,
                    'total'                   => $subtotal,
                    'notes'                   => $b2bOrder->so_number,
                    'source_type'             => 'agent_b2b',
                    'source_id'               => $agentId,
                    'source_reference'        => $b2bOrder->so_number,
                    'inventory_sale_order_id' => $b2bOrder->id,
                ]);

                $b2bInvoice->items()->createMany(
                    collect($resolvedItems)->map(fn ($i) => [
                        'description' => $i['product_name'] ?? ('Product #' . $i['product_id']),
                        'quantity'    => $i['qty_ordered'],
                        'unit_price'  => $i['b2b_price'],
                        'amount'      => $i['qty_ordered'] * $i['b2b_price'],
                    ])->all(),
                );

                \Centrex\Accounting\Facades\Accounting::postInvoice($b2bInvoice);

                DB::table($soTable)->where('id', $b2bOrder->id)
                    ->update(['accounting_invoice_id' => $b2bInvoice->id]);
            }
        } catch (\Throwable) {
            // non-fatal
        }
    }

    /**
     * Resolve B2C and B2B prices for every item in the list.
     * Falls back to explicitly passed unit_price_local when no catalogue price exists.
     */
    private function resolveItemPrices(array $items, int $warehouseId, string $b2cTier, string $b2bTier): array
    {
        $productIds = array_filter(array_column($items, 'product_id'));
        $productNames = Product::whereIn('id', $productIds)
            ->pluck('name', 'id')
            ->all();

        return array_map(function (array $item) use ($warehouseId, $b2cTier, $b2bTier, $productNames): array {
            $productId = (int) $item['product_id'];
            $variantId = isset($item['variant_id']) ? (int) $item['variant_id'] : null;

            try {
                $b2cPrice = (float) Inventory::resolvePrice($productId, $b2cTier, $warehouseId, variantId: $variantId)->price_amount;
            } catch (PriceNotFoundException) {
                $b2cPrice = (float) ($item['unit_price_local'] ?? 0);
            }

            try {
                $b2bPrice = (float) Inventory::resolvePrice($productId, $b2bTier, $warehouseId, variantId: $variantId)->price_amount;
            } catch (PriceNotFoundException) {
                $b2bPrice = round($b2cPrice * 0.75, 4);
            }

            return array_merge($item, [
                'b2c_price'    => $b2cPrice,
                'b2b_price'    => $b2bPrice,
                'product_name' => $productNames[$productId] ?? ('Product #' . $productId),
            ]);
        }, $items);
    }

    private function assertRole(SaleOrder $order, OrderRole ...$allowed): void
    {
        $role = $order->order_role ?? OrderRole::DIRECT;

        if (!in_array($role, $allowed, true)) {
            $allowedValues = array_map(fn (OrderRole $r) => $r->value, $allowed);

            throw new \InvalidArgumentException(
                'Expected order role [' . implode('|', $allowedValues) . "], got [{$role->value}], on SO #{$order->id}.",
            );
        }
    }
}

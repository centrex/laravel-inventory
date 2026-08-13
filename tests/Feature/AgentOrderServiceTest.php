<?php

declare(strict_types = 1);

use Centrex\Inventory\Enums\OrderRole;
use Centrex\Inventory\Facades\Inventory;
use Centrex\Inventory\Models\{Agent, Customer, Product, SaleOrder, Warehouse};
use Centrex\Inventory\Services\AgentOrderService;

function makeAgentWarehouse(string $code = 'AW1'): Warehouse
{
    return Warehouse::create(['code' => $code, 'name' => 'Agent Warehouse', 'country_code' => 'BD', 'currency' => 'BDT', 'is_default' => true]);
}

function makeAgentProduct(string $sku = 'AGT-SKU-1'): Product
{
    return Product::create(['sku' => $sku, 'name' => 'Agent Product', 'unit' => 'pcs', 'is_active' => true, 'is_stockable' => true]);
}

function makeAgentWithCustomer(string $code = 'AGT-1'): Agent
{
    $agentCustomer = Customer::create([
        'code'            => $code . '-CUST', 'name' => 'Agent Billing Customer', 'currency' => 'BDT',
        'price_tier_code' => 'b2b_wholesale', 'is_active' => true, 'is_agent' => true,
    ]);

    return Agent::create([
        'code'        => $code, 'name' => 'Test Agent', 'price_tier_code' => 'b2b_wholesale',
        'customer_id' => $agentCustomer->id, 'is_active' => true,
    ]);
}

it('creates a paired B2C/B2B order and stamps agent metadata on both', function (): void {
    $warehouse = makeAgentWarehouse();
    $product = makeAgentProduct();
    $agent = makeAgentWithCustomer();
    $endCustomer = Customer::create(['code' => 'END-1', 'name' => 'End Customer', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);

    $result = app(AgentOrderService::class)->createPairedOrders(
        b2cData: [
            'warehouse_id' => $warehouse->id,
            'customer_id'  => $endCustomer->id,
            'currency'     => 'BDT',
            'items'        => [
                ['product_id' => $product->id, 'qty_ordered' => 2, 'unit_price_local' => 100.0],
            ],
        ],
        agentId: $agent->id,
    );

    $b2c = $result['b2c_order'];
    $b2b = $result['b2b_order'];

    expect($b2c->order_role)->toBe(OrderRole::AGENT_B2C)
        ->and($b2b->order_role)->toBe(OrderRole::AGENT_B2B)
        ->and($b2c->paired_sale_order_id)->toBe($b2b->id)
        ->and($b2b->paired_sale_order_id)->toBe($b2c->id)
        ->and($b2c->agent_customer_id)->toBe($agent->customer_id)
        ->and($b2b->agent_customer_id)->toBe($agent->customer_id)
        ->and($b2c->customer_id)->toBe($endCustomer->id)
        ->and($b2b->customer_id)->toBe($agent->customer_id)
        // B2C is retail (unit_price_local passed as-is: 100), B2B falls back to 75% (no catalogue price)
        ->and((float) $b2c->total_amount)->toBe(200.0)
        ->and((float) $b2b->total_amount)->toBe(150.0);
});

it('throws when the agent has no linked billing customer', function (): void {
    $agent = Agent::create(['code' => 'AGT-NOLINK', 'name' => 'No Link Agent', 'is_active' => true]);
    $warehouse = makeAgentWarehouse('AW-NL');
    $product = makeAgentProduct('SKU-NL');
    $endCustomer = Customer::create(['code' => 'END-NL', 'name' => 'End Customer', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);

    app(AgentOrderService::class)->createPairedOrders(
        b2cData: [
            'warehouse_id' => $warehouse->id,
            'customer_id'  => $endCustomer->id,
            'items'        => [['product_id' => $product->id, 'qty_ordered' => 1, 'unit_price_local' => 50.0]],
        ],
        agentId: $agent->id,
    );
})->throws(InvalidArgumentException::class);

it('confirming and reserving the B2B order mirrors its lifecycle onto the paired B2C order', function (): void {
    $warehouse = makeAgentWarehouse('AW2');
    $product = makeAgentProduct('SKU-2');
    $agent = makeAgentWithCustomer('AGT-2');
    $endCustomer = Customer::create(['code' => 'END-2', 'name' => 'End Customer 2', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);

    $result = app(AgentOrderService::class)->createPairedOrders(
        b2cData: [
            'warehouse_id' => $warehouse->id,
            'customer_id'  => $endCustomer->id,
            'items'        => [['product_id' => $product->id, 'qty_ordered' => 1, 'unit_price_local' => 100.0]],
        ],
        agentId: $agent->id,
    );

    $confirmed = app(AgentOrderService::class)->confirmAndReserve($result['b2b_order']);

    // B2B goes confirmed -> processing (stock reserved); the observer mirrors both
    // transitions onto the paired B2C order, so it ends at 'processing' too.
    expect($confirmed['b2b_order']->status->value)->toBe('processing')
        ->and(SaleOrder::find($confirmed['b2c_order']->id)->status->value)->toBe('processing');
});

it('the paired-order observer mirrors B2B status transitions onto B2C without double-processing', function (): void {
    $warehouse = makeAgentWarehouse('AW3');
    $product = makeAgentProduct('SKU-3');
    $agent = makeAgentWithCustomer('AGT-3');
    $endCustomer = Customer::create(['code' => 'END-3', 'name' => 'End Customer 3', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);

    $result = app(AgentOrderService::class)->createPairedOrders(
        b2cData: [
            'warehouse_id' => $warehouse->id,
            'customer_id'  => $endCustomer->id,
            'items'        => [['product_id' => $product->id, 'qty_ordered' => 1, 'unit_price_local' => 100.0]],
        ],
        agentId: $agent->id,
    );

    // Directly mutate + save the B2B order's status (bypassing Inventory:: business logic)
    // to isolate the observer's own mirroring behavior.
    $b2b = $result['b2b_order'];
    $b2b->status = 'cancelled';
    $b2b->save();

    $b2c = SaleOrder::find($result['b2c_order']->id);

    expect($b2c->status->value)->toBe('cancelled');
});

it('cancelling either side of the pair cancels both orders', function (): void {
    $warehouse = makeAgentWarehouse('AW4');
    $product = makeAgentProduct('SKU-4');
    $agent = makeAgentWithCustomer('AGT-4');
    $endCustomer = Customer::create(['code' => 'END-4', 'name' => 'End Customer 4', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);

    $result = app(AgentOrderService::class)->createPairedOrders(
        b2cData: [
            'warehouse_id' => $warehouse->id,
            'customer_id'  => $endCustomer->id,
            'items'        => [['product_id' => $product->id, 'qty_ordered' => 1, 'unit_price_local' => 100.0]],
        ],
        agentId: $agent->id,
    );

    app(AgentOrderService::class)->cancelPair($result['b2c_order']);

    expect(SaleOrder::find($result['b2c_order']->id)->status->value)->toBe('cancelled')
        ->and(SaleOrder::find($result['b2b_order']->id)->status->value)->toBe('cancelled');
});

it('computes agent margin from the paired order totals', function (): void {
    $warehouse = makeAgentWarehouse('AW5');
    $product = makeAgentProduct('SKU-5');
    $agent = makeAgentWithCustomer('AGT-5');
    $endCustomer = Customer::create(['code' => 'END-5', 'name' => 'End Customer 5', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);

    $result = app(AgentOrderService::class)->createPairedOrders(
        b2cData: [
            'warehouse_id' => $warehouse->id,
            'customer_id'  => $endCustomer->id,
            'items'        => [['product_id' => $product->id, 'qty_ordered' => 4, 'unit_price_local' => 100.0]],
        ],
        agentId: $agent->id,
    );

    $margin = app(AgentOrderService::class)->agentMargin($result['b2c_order']);

    expect($margin['b2c_total'])->toBe(400.0)
        ->and($margin['b2b_total'])->toBe(300.0)
        ->and($margin['margin'])->toBe(100.0)
        ->and($margin['margin_pct'])->toBe(25.0);
});

it('agentMargin rejects an order that is not in the AGENT_B2C role', function (): void {
    $warehouse = makeAgentWarehouse('AW6');
    $product = makeAgentProduct('SKU-6');
    $customer = Customer::create(['code' => 'DIRECT-1', 'name' => 'Direct Customer', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);

    $directOrder = Inventory::createSaleOrder([
        'warehouse_id' => $warehouse->id,
        'customer_id'  => $customer->id,
        'currency'     => 'BDT',
        'items'        => [['product_id' => $product->id, 'qty_ordered' => 1, 'unit_price_local' => 50.0]],
    ]);

    app(AgentOrderService::class)->agentMargin($directOrder);
})->throws(InvalidArgumentException::class);

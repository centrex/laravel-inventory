<?php

declare(strict_types = 1);

use Centrex\Accounting\Accounting;
use Centrex\Accounting\Models\{Customer as AccountingCustomer, Invoice};
use Centrex\Inventory\Models\{Agent, Customer, Product, Warehouse};
use Centrex\Inventory\Services\AgentOrderService;

beforeEach(function (): void {
    app(Accounting::class)->initializeChartOfAccounts();
});

it('auto-posts paired B2C and B2B invoices when accounting integration is enabled and customers are linked', function (): void {
    config(['inventory.erp.accounting.enabled' => true]);

    $warehouse = Warehouse::create(['code' => 'AWI-1', 'name' => 'WH', 'country_code' => 'BD', 'currency' => 'BDT', 'is_default' => true]);
    $product = Product::create(['sku' => 'AWI-SKU-1', 'name' => 'Widget', 'unit' => 'pcs', 'is_active' => true, 'is_stockable' => true]);

    $acctB2c = AccountingCustomer::create(['code' => 'AWI-B2C-1', 'name' => 'End Customer Ledger']);
    $acctAgent = AccountingCustomer::create(['code' => 'AWI-AGT-1', 'name' => 'Agent Ledger']);

    $agentBillingCustomer = Customer::create([
        'code'                   => 'AWI-AGT-CUST', 'name' => 'Agent Billing', 'currency' => 'BDT',
        'price_tier_code'        => 'b2b_wholesale', 'is_active' => true, 'is_agent' => true,
        'accounting_customer_id' => $acctAgent->id,
    ]);
    $agent = Agent::create(['code' => 'AWI-AGT', 'name' => 'Test Agent', 'customer_id' => $agentBillingCustomer->id, 'is_active' => true]);

    $endCustomer = Customer::create([
        'code'                   => 'AWI-END', 'name' => 'End Customer', 'currency' => 'BDT',
        'price_tier_code'        => 'b2c_retail', 'is_active' => true,
        'accounting_customer_id' => $acctB2c->id,
    ]);

    $result = app(AgentOrderService::class)->createPairedOrders(
        b2cData: [
            'warehouse_id' => $warehouse->id,
            'customer_id'  => $endCustomer->id,
            'currency'     => 'BDT',
            'items'        => [['product_id' => $product->id, 'qty_ordered' => 3, 'unit_price_local' => 100.0]],
        ],
        agentId: $agent->id,
    );

    $b2cInvoice = Invoice::where('source_type', 'agent_b2c')->where('source_id', $result['b2c_order']->id)->first();
    $b2bInvoice = Invoice::where('source_type', 'agent_b2b')->where('source_id', $agent->id)->first();

    expect($b2cInvoice)->not->toBeNull()
        ->and((float) $b2cInvoice->total)->toBe(300.0)
        ->and($b2cInvoice->customer_id)->toBe($acctB2c->id)
        ->and($b2bInvoice)->not->toBeNull()
        ->and((float) $b2bInvoice->total)->toBe(225.0)
        ->and($b2bInvoice->customer_id)->toBe($acctAgent->id);

    expect($result['b2c_order']->fresh()->accounting_invoice_id)->toBe($b2cInvoice->id)
        ->and($result['b2b_order']->fresh()->accounting_invoice_id)->toBe($b2bInvoice->id);
});

it('does not post accounting invoices when the integration is disabled', function (): void {
    config(['inventory.erp.accounting.enabled' => false]);

    $warehouse = Warehouse::create(['code' => 'AWI-2', 'name' => 'WH2', 'country_code' => 'BD', 'currency' => 'BDT', 'is_default' => true]);
    $product = Product::create(['sku' => 'AWI-SKU-2', 'name' => 'Widget 2', 'unit' => 'pcs', 'is_active' => true, 'is_stockable' => true]);

    $acctB2c = AccountingCustomer::create(['code' => 'AWI-B2C-2', 'name' => 'End Customer Ledger 2']);
    $agentBillingCustomer = Customer::create([
        'code'            => 'AWI-AGT-CUST2', 'name' => 'Agent Billing 2', 'currency' => 'BDT',
        'price_tier_code' => 'b2b_wholesale', 'is_active' => true, 'is_agent' => true,
    ]);
    $agent = Agent::create(['code' => 'AWI-AGT2', 'name' => 'Test Agent 2', 'customer_id' => $agentBillingCustomer->id, 'is_active' => true]);
    $endCustomer = Customer::create([
        'code'            => 'AWI-END2', 'name' => 'End Customer 2', 'currency' => 'BDT',
        'price_tier_code' => 'b2c_retail', 'is_active' => true, 'accounting_customer_id' => $acctB2c->id,
    ]);

    $result = app(AgentOrderService::class)->createPairedOrders(
        b2cData: [
            'warehouse_id' => $warehouse->id,
            'customer_id'  => $endCustomer->id,
            'currency'     => 'BDT',
            'items'        => [['product_id' => $product->id, 'qty_ordered' => 1, 'unit_price_local' => 50.0]],
        ],
        agentId: $agent->id,
    );

    expect(Invoice::where('source_type', 'agent_b2c')->where('source_id', $result['b2c_order']->id)->exists())->toBeFalse()
        ->and($result['b2c_order']->fresh()->accounting_invoice_id)->toBeNull();
});

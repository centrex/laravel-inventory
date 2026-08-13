<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Agents\{AgentCustomerCreatePage, AgentCustomersPage, AgentDashboard, AgentFormPage, AgentIndexPage, AgentInvoicesPage, AgentSaleOrderFormPage, ProAnalyticsDashboard};
use Centrex\Inventory\Models\{Agent, Customer, Product, Warehouse};
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function (): void {
    foreach (['inventory.agents.view', 'inventory.agents.manage', 'inventory.customers.view', 'inventory.invoices.view', 'inventory.agent-orders.create'] as $ability) {
        Gate::define($ability, fn ($user = null) => true);
    }
});

it('renders the agent index page', function (): void {
    $customer = Customer::create(['code' => 'LW-C1', 'name' => 'Agent Billing', 'currency' => 'BDT', 'price_tier_code' => 'b2b_wholesale', 'is_active' => true]);
    Agent::create(['code' => 'LW-A1', 'name' => 'Listed Agent', 'customer_id' => $customer->id, 'is_active' => true]);

    Livewire::test(AgentIndexPage::class)
        ->assertOk()
        ->assertSee('Listed Agent');
});

it('renders the agent form page for create and edit', function (): void {
    Livewire::test(AgentFormPage::class)
        ->assertOk()
        ->assertSet('agentId', null);

    $customer = Customer::create(['code' => 'LW-C2', 'name' => 'Agent Billing 2', 'currency' => 'BDT', 'price_tier_code' => 'b2b_wholesale', 'is_active' => true]);
    $agent = Agent::create(['code' => 'LW-A2', 'name' => 'Editable Agent', 'customer_id' => $customer->id, 'is_active' => true]);

    Livewire::test(AgentFormPage::class, ['agentId' => $agent->id])
        ->assertOk()
        ->assertSet('name', 'Editable Agent');
});

it('renders the agent customers page and can detach a customer', function (): void {
    $billingCustomer = Customer::create(['code' => 'LW-C3', 'name' => 'Agent Billing 3', 'currency' => 'BDT', 'price_tier_code' => 'b2b_wholesale', 'is_active' => true]);
    $agent = Agent::create(['code' => 'LW-A3', 'name' => 'Agent With Customers', 'customer_id' => $billingCustomer->id, 'is_active' => true]);
    $linked = Customer::create(['code' => 'LW-C3-LINKED', 'name' => 'Linked Customer', 'currency' => 'BDT', 'price_tier_code' => 'b2c_retail', 'is_active' => true]);
    $agent->customers()->attach($linked->id, ['is_primary' => true]);

    Livewire::test(AgentCustomersPage::class, ['agentId' => $agent->id])
        ->assertOk()
        ->assertSee('Linked Customer')
        ->call('detach', $linked->id)
        ->assertSee('Customer removed from agent.');

    expect($agent->customers()->count())->toBe(0);
});

it('creates a new customer linked to the agent via the create page', function (): void {
    $billingCustomer = Customer::create(['code' => 'LW-C4', 'name' => 'Agent Billing 4', 'currency' => 'BDT', 'price_tier_code' => 'b2b_wholesale', 'is_active' => true]);
    $agent = Agent::create(['code' => 'LW-A4', 'name' => 'Agent For New Customer', 'customer_id' => $billingCustomer->id, 'is_active' => true]);

    Livewire::test(AgentCustomerCreatePage::class, ['agentId' => $agent->id])
        ->assertOk()
        ->set('name', 'Brand New Customer')
        ->set('code', 'BRAND-NEW-1')
        ->set('creditLimitAmount', '5000')
        ->call('save')
        ->assertSee('created and linked to this agent');

    $created = Customer::where('name', 'Brand New Customer')->first();
    expect($created)->not->toBeNull()
        ->and($created->agent_id)->toBe($agent->id)
        ->and($agent->customers()->where('customer_id', $created->id)->exists())->toBeTrue();
});

it('requires a customer code on the create page (matches the DB unique/not-null constraint)', function (): void {
    $billingCustomer = Customer::create(['code' => 'LW-C4B', 'name' => 'Agent Billing 4b', 'currency' => 'BDT', 'price_tier_code' => 'b2b_wholesale', 'is_active' => true]);
    $agent = Agent::create(['code' => 'LW-A4B', 'name' => 'Agent For New Customer B', 'customer_id' => $billingCustomer->id, 'is_active' => true]);

    Livewire::test(AgentCustomerCreatePage::class, ['agentId' => $agent->id])
        ->set('name', 'No Code Customer')
        ->set('code', '')
        ->call('save')
        ->assertHasErrors(['code' => 'required']);
});

it('renders the agent invoices page without accounting integration configured', function (): void {
    $customer = Customer::create(['code' => 'LW-C5', 'name' => 'Agent Billing 5', 'currency' => 'BDT', 'price_tier_code' => 'b2b_wholesale', 'is_active' => true]);
    $agent = Agent::create(['code' => 'LW-A5', 'name' => 'Invoiceless Agent', 'customer_id' => $customer->id, 'is_active' => true]);

    Livewire::test(AgentInvoicesPage::class, ['agentId' => $agent->id])
        ->assertOk()
        ->assertSee('Invoiceless Agent');
});

it('renders the agent dashboard', function (): void {
    $customer = Customer::create(['code' => 'LW-C6', 'name' => 'Agent Billing 6', 'currency' => 'BDT', 'price_tier_code' => 'b2b_wholesale', 'is_active' => true]);
    $agent = Agent::create(['code' => 'LW-A6', 'name' => 'Dashboard Agent', 'customer_id' => $customer->id, 'is_active' => true]);

    Livewire::test(AgentDashboard::class, ['agentId' => $agent->id])
        ->assertOk()
        ->assertSee('Dashboard Agent');
});

it('renders the agent sale order form page', function (): void {
    Warehouse::create(['code' => 'LW-W1', 'name' => 'LW Warehouse', 'country_code' => 'BD', 'currency' => 'BDT', 'is_default' => true]);
    Product::create(['sku' => 'LW-SKU-1', 'name' => 'LW Product', 'unit' => 'pcs', 'is_active' => true, 'is_stockable' => true]);
    $customer = Customer::create(['code' => 'LW-C7', 'name' => 'Agent Billing 7', 'currency' => 'BDT', 'price_tier_code' => 'b2b_wholesale', 'is_active' => true]);
    Agent::create(['code' => 'LW-A7', 'name' => 'Order Form Agent', 'customer_id' => $customer->id, 'is_active' => true]);

    Livewire::test(AgentSaleOrderFormPage::class)
        ->assertOk()
        ->assertSee('LW Product')
        ->assertSee('Order Form Agent');
});

it('renders the cross-agent analytics dashboard', function (): void {
    Livewire::test(ProAnalyticsDashboard::class)
        ->assertOk();
});

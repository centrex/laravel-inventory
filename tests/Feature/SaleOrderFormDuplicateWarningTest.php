<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Transactions\SaleOrderFormPage;
use Centrex\Inventory\Models\{Customer, Product, SaleOrder, Warehouse, WarehouseProduct};
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\{Auth, Gate};
use Livewire\Livewire;

function duplicateWarningFakeUser(int $id = 1): void
{
    $user = new class($id) implements Authenticatable
    {
        public function __construct(private int $id) {}

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return $this->id;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): ?string
        {
            return null;
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return '';
        }
    };

    Auth::login($user);
}

function duplicateWarningFixture(): array
{
    Gate::define('inventory.sale-orders.create', fn ($user = null) => true);
    Gate::define('inventory.sale-orders.approve-credit', fn ($user = null) => true);

    $warehouse = Warehouse::create([
        'code' => 'W-DUPW', 'name' => 'DupWarn Warehouse', 'country_code' => 'GB', 'currency' => 'BDT', 'is_active' => true,
    ]);
    $customer = Customer::create([
        'code'            => 'CUS-DUPW', 'name' => 'DupWarn Customer', 'currency' => 'BDT',
        'price_tier_code' => 'b2b_retail', 'is_active' => true,
    ]);
    $product = Product::create([
        'sku' => 'SKU-DUPW', 'name' => 'Widget', 'unit' => 'pcs', 'is_active' => true, 'is_stockable' => true,
    ]);
    WarehouseProduct::create([
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'qty_on_hand'  => 100, 'qty_reserved' => 0, 'qty_in_transit' => 0, 'wac_amount' => 10,
    ]);

    return compact('warehouse', 'customer', 'product');
}

it('warns instead of creating when the same order is resubmitted minutes later (fresh page load, not a race)', function (): void {
    duplicateWarningFakeUser();
    ['warehouse' => $warehouse, 'customer' => $customer, 'product' => $product] = duplicateWarningFixture();

    Livewire::test(SaleOrderFormPage::class)
        ->set('warehouse_id', $warehouse->id)
        ->set('customer_id', $customer->id)
        ->set('items.0.product_id', $product->id)
        ->set('items.0.qty_ordered', 2)
        ->set('items.0.unit_price_local', 10)
        ->call('save')
        ->assertHasNoErrors();

    expect(SaleOrder::count())->toBe(1);

    // A genuinely new request (page reload) — a fresh Livewire::test() call means a fresh
    // component instance and a fresh form_token, so GuardsAgainstDuplicateSubmission's
    // in-flight lock (keyed by that token) plays no part in this scenario at all.
    $component = Livewire::test(SaleOrderFormPage::class)
        ->set('warehouse_id', $warehouse->id)
        ->set('customer_id', $customer->id)
        ->set('items.0.product_id', $product->id)
        ->set('items.0.qty_ordered', 2)
        ->set('items.0.unit_price_local', 10)
        ->call('save');

    expect(SaleOrder::count())->toBe(1)
        ->and($component->get('duplicateOrderWarning'))->not->toBeNull()
        ->and($component->get('duplicateOrderWarning')['so_number'])->toBeString();

    // Confirming proceeds to actually create the second order.
    $component->call('confirmDuplicateAndSave')->assertHasNoErrors();

    expect(SaleOrder::count())->toBe(2);
});

it('does not warn for a different item set from the same customer/warehouse', function (): void {
    duplicateWarningFakeUser();
    ['warehouse' => $warehouse, 'customer' => $customer, 'product' => $product] = duplicateWarningFixture();

    $secondProduct = Product::create([
        'sku' => 'SKU-DUPW-2', 'name' => 'Gadget', 'unit' => 'pcs', 'is_active' => true, 'is_stockable' => true,
    ]);
    WarehouseProduct::create([
        'warehouse_id' => $warehouse->id, 'product_id' => $secondProduct->id,
        'qty_on_hand'  => 100, 'qty_reserved' => 0, 'qty_in_transit' => 0, 'wac_amount' => 10,
    ]);

    Livewire::test(SaleOrderFormPage::class)
        ->set('warehouse_id', $warehouse->id)
        ->set('customer_id', $customer->id)
        ->set('items.0.product_id', $product->id)
        ->set('items.0.qty_ordered', 2)
        ->set('items.0.unit_price_local', 10)
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test(SaleOrderFormPage::class)
        ->set('warehouse_id', $warehouse->id)
        ->set('customer_id', $customer->id)
        ->set('items.0.product_id', $secondProduct->id)
        ->set('items.0.qty_ordered', 3)
        ->set('items.0.unit_price_local', 20)
        ->call('save')
        ->assertHasNoErrors();

    expect(SaleOrder::count())->toBe(2);
});

it('does not warn once the earlier order falls outside the duplicate-detection window', function (): void {
    duplicateWarningFakeUser();
    ['warehouse' => $warehouse, 'customer' => $customer, 'product' => $product] = duplicateWarningFixture();

    Livewire::test(SaleOrderFormPage::class)
        ->set('warehouse_id', $warehouse->id)
        ->set('customer_id', $customer->id)
        ->set('items.0.product_id', $product->id)
        ->set('items.0.qty_ordered', 2)
        ->set('items.0.unit_price_local', 10)
        ->call('save')
        ->assertHasNoErrors();

    SaleOrder::query()->update(['created_at' => now()->subMinutes(10)]);

    Livewire::test(SaleOrderFormPage::class)
        ->set('warehouse_id', $warehouse->id)
        ->set('customer_id', $customer->id)
        ->set('items.0.product_id', $product->id)
        ->set('items.0.qty_ordered', 2)
        ->set('items.0.unit_price_local', 10)
        ->call('save')
        ->assertHasNoErrors();

    expect(SaleOrder::count())->toBe(2);
});

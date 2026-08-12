<?php

declare(strict_types = 1);

use Centrex\Inventory\Models\{Customer, Product, SaleOrder, Warehouse, WarehouseProduct};
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\{Auth, Gate};

function apiIdempotencyFakeUser(int $id = 1): void
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

it('returns the existing order on a content-identical API retry instead of creating a duplicate', function (): void {
    apiIdempotencyFakeUser();
    Gate::define('inventory.sale-orders.create', fn ($user = null) => true);

    $warehouse = Warehouse::create([
        'code' => 'W-API-IDEM', 'name' => 'API Idem Warehouse', 'country_code' => 'GB', 'currency' => 'BDT', 'is_active' => true,
    ]);
    $customer = Customer::create([
        'code'            => 'CUS-API-IDEM', 'name' => 'API Idem Customer', 'currency' => 'BDT',
        'price_tier_code' => 'b2b_retail', 'is_active' => true,
    ]);
    $product = Product::create([
        'sku' => 'SKU-API-IDEM', 'name' => 'Widget', 'unit' => 'pcs', 'is_active' => true, 'is_stockable' => true,
    ]);
    WarehouseProduct::create([
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'qty_on_hand'  => 100, 'qty_reserved' => 0, 'qty_in_transit' => 0, 'wac_amount' => 10,
    ]);

    $payload = [
        'warehouse_id' => $warehouse->id,
        'customer_id'  => $customer->id,
        'currency'     => 'BDT',
        'items'        => [
            ['product_id' => $product->id, 'qty_ordered' => 2, 'unit_price_local' => 10],
        ],
    ];

    // Simulates a client that timed out waiting on the first (slow) response and retried
    // the identical request — this used to create a second, third, ... SaleOrder every time.
    $first = $this->postJson('/api/inventory/sale-orders', $payload);
    $first->assertStatus(201);

    $second = $this->postJson('/api/inventory/sale-orders', $payload);
    $second->assertStatus(200);

    $third = $this->postJson('/api/inventory/sale-orders', $payload);
    $third->assertStatus(200);

    expect(SaleOrder::count())->toBe(1)
        ->and($second->json('data.id'))->toBe($first->json('data.id'))
        ->and($third->json('data.id'))->toBe($first->json('data.id'));
});

it('creates a genuinely separate order for a different item set from the same customer/warehouse', function (): void {
    apiIdempotencyFakeUser();
    Gate::define('inventory.sale-orders.create', fn ($user = null) => true);

    $warehouse = Warehouse::create([
        'code' => 'W-API-IDEM-2', 'name' => 'API Idem Warehouse 2', 'country_code' => 'GB', 'currency' => 'BDT', 'is_active' => true,
    ]);
    $customer = Customer::create([
        'code'            => 'CUS-API-IDEM-2', 'name' => 'API Idem Customer 2', 'currency' => 'BDT',
        'price_tier_code' => 'b2b_retail', 'is_active' => true,
    ]);
    $product = Product::create([
        'sku' => 'SKU-API-IDEM-2', 'name' => 'Widget', 'unit' => 'pcs', 'is_active' => true, 'is_stockable' => true,
    ]);
    WarehouseProduct::create([
        'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        'qty_on_hand'  => 100, 'qty_reserved' => 0, 'qty_in_transit' => 0, 'wac_amount' => 10,
    ]);

    $this->postJson('/api/inventory/sale-orders', [
        'warehouse_id' => $warehouse->id,
        'customer_id'  => $customer->id,
        'currency'     => 'BDT',
        'items'        => [['product_id' => $product->id, 'qty_ordered' => 2, 'unit_price_local' => 10]],
    ])->assertStatus(201);

    $this->postJson('/api/inventory/sale-orders', [
        'warehouse_id' => $warehouse->id,
        'customer_id'  => $customer->id,
        'currency'     => 'BDT',
        'items'        => [['product_id' => $product->id, 'qty_ordered' => 5, 'unit_price_local' => 10]],
    ])->assertStatus(201);

    expect(SaleOrder::count())->toBe(2);
});

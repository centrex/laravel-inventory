<?php

declare(strict_types = 1);

use Centrex\Inventory\Models\{Lot, Product, Warehouse};
use Centrex\Inventory\Support\InventoryEntityRegistry;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;

function actingAsInventoryAdmin(): void
{
    Gate::define('inventory-admin', fn () => true);
    test()->actingAs(new class() extends Authenticatable
    {
        protected $table = 'users';

        public $id = 1;
    });
}

it('registers lots as a master-data entity', function (): void {
    expect(InventoryEntityRegistry::masterDataEntities())->toContain('lots');
});

it('creates, lists, updates, and deletes a lot through the generic api', function (): void {
    actingAsInventoryAdmin();

    $warehouse = Warehouse::create([
        'code'         => 'W-LOT-1',
        'name'         => 'Lot Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $product = Product::create([
        'sku'          => 'SKU-LOT-1',
        'name'         => 'Lot Product',
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);

    $response = $this->postJson('/api/inventory/lots', [
        'lot_number'   => 'LOT-001',
        'product_id'   => $product->id,
        'warehouse_id' => $warehouse->id,
        'expires_at'   => '2027-01-01',
    ]);

    $response->assertCreated();
    $lotId = (int) $response->json('id');

    $this->getJson('/api/inventory/lots')
        ->assertOk()
        ->assertJsonFragment(['lot_number' => 'LOT-001']);

    $this->putJson("/api/inventory/lots/{$lotId}", [
        'lot_number'   => 'LOT-001',
        'product_id'   => $product->id,
        'warehouse_id' => $warehouse->id,
        'notes'        => 'Updated notes',
    ])->assertOk()->assertJsonFragment(['notes' => 'Updated notes']);

    $this->deleteJson("/api/inventory/lots/{$lotId}")->assertOk();

    expect(Lot::find($lotId))->toBeNull();
});

it('rejects a duplicate lot_number for the same product/variant/warehouse', function (): void {
    actingAsInventoryAdmin();

    $warehouse = Warehouse::create([
        'code'         => 'W-LOT-2',
        'name'         => 'Lot Warehouse 2',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $product = Product::create([
        'sku'          => 'SKU-LOT-2',
        'name'         => 'Lot Product 2',
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);

    Lot::create([
        'lot_number'   => 'LOT-DUP',
        'product_id'   => $product->id,
        'warehouse_id' => $warehouse->id,
        'qty_initial'  => 0,
        'qty_on_hand'  => 0,
    ]);

    $this->postJson('/api/inventory/lots', [
        'lot_number'   => 'LOT-DUP',
        'product_id'   => $product->id,
        'warehouse_id' => $warehouse->id,
    ])->assertJsonValidationErrors(['lot_number']);
});

it('does not expose qty_initial/qty_on_hand as editable form fields', function (): void {
    $fields = collect(InventoryEntityRegistry::definition('lots')['form_fields'])->pluck('name');

    expect($fields)->not->toContain('qty_initial')
        ->and($fields)->not->toContain('qty_on_hand');
});

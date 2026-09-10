<?php

declare(strict_types = 1);

use Centrex\Inventory\Enums\MovementType;
use Centrex\Inventory\Http\Livewire\Entities\{EntityFormPage, WarehouseProductMovementsPage};
use Centrex\Inventory\Models\{Product, StockMovement, Warehouse, WarehouseProduct};
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/**
 * Regression coverage for three related gaps on the warehouse-products stock ledger:
 *
 *  - No invariant stopped any quantity/cost column from going negative (the generic
 *    master-data CRUD form had no min:0 rule on qty_on_hand/qty_reserved/qty_in_transit,
 *    and nothing enforced it below that either).
 *  - That same generic form let qty_on_hand/qty_reserved/qty_in_transit be edited directly,
 *    bypassing every lock + inv_stock_movements write that GRN/sale/transfer/adjustment
 *    flows go through — silently desyncing the ledger from its own audit trail.
 *  - There was no UI at all to see a warehouse product's inv_stock_movements history.
 */
function warehouseProductFixture(string $suffix): array
{
    $warehouse = Warehouse::create([
        'code' => "W-INTEGRITY-{$suffix}", 'name' => "Integrity Warehouse {$suffix}", 'country_code' => 'BD', 'currency' => 'BDT',
    ]);
    $product = Product::create([
        'sku' => "SKU-INTEGRITY-{$suffix}", 'name' => "Integrity Product {$suffix}", 'unit' => 'pcs', 'is_stockable' => true,
    ]);
    $stock = WarehouseProduct::create([
        'warehouse_id' => $warehouse->id,
        'product_id'   => $product->id,
        'qty_on_hand'  => 10,
        'wac_amount'   => 100,
    ]);

    return [$warehouse, $product, $stock];
}

it('rejects a negative quantity or cost on the warehouse product ledger', function (): void {
    [$warehouse, $product, $stock] = warehouseProductFixture('NEGATIVE');

    expect(fn () => $stock->update(['qty_on_hand' => -1]))->toThrow(InvalidArgumentException::class);
    expect(fn () => $stock->update(['qty_reserved' => -1]))->toThrow(InvalidArgumentException::class);
    expect(fn () => $stock->update(['qty_in_transit' => -1]))->toThrow(InvalidArgumentException::class);
    expect(fn () => $stock->update(['qty_damaged' => -1]))->toThrow(InvalidArgumentException::class);
    expect(fn () => $stock->update(['wac_amount' => -1]))->toThrow(InvalidArgumentException::class);

    // Nothing was actually persisted by any of the rejected writes.
    expect((float) $stock->fresh()->qty_on_hand)->toBe(10.0);
});

it('allows a zero-touching update that does not actually go negative', function (): void {
    [$warehouse, $product, $stock] = warehouseProductFixture('ZERO');

    $stock->update(['qty_on_hand' => 0, 'qty_reserved' => 0]);

    expect((float) $stock->fresh()->qty_on_hand)->toBe(0.0);
});

it('no longer exposes on-hand/reserved/in-transit quantities as editable on the master-data form', function (): void {
    [$warehouse, $product, $stock] = warehouseProductFixture('FORMFIELDS');
    Gate::define('inventory.master-data.manage', fn ($user = null) => true);

    Livewire::test(EntityFormPage::class, ['entity' => 'warehouse-products', 'recordId' => $stock->id])
        ->assertSet('form.qty_on_hand', null)
        ->assertSet('form.qty_reserved', null)
        ->assertSet('form.qty_in_transit', null)
        ->set('form.bin_location', 'A1-01')
        ->call('save');

    // Only the still-editable metadata field changed; the physical ledger is untouched.
    $stock->refresh();
    expect($stock->bin_location)->toBe('A1-01');
    expect((float) $stock->qty_on_hand)->toBe(10.0);
});

it('shows a warehouse product movement history page with matching before/after quantities', function (): void {
    [$warehouse, $product, $stock] = warehouseProductFixture('MOVEMENTS');
    Gate::define('inventory.reports.view', fn ($user = null) => true);

    StockMovement::create([
        'warehouse_id'     => $warehouse->id,
        'product_id'       => $product->id,
        'movement_type'    => MovementType::PURCHASE_RECEIPT,
        'direction'        => 'in',
        'qty'              => 10,
        'qty_before'       => 0,
        'qty_after'        => 10,
        'unit_cost_amount' => 100,
        'wac_amount'       => 100,
        'moved_at'         => now(),
    ]);

    Livewire::test(WarehouseProductMovementsPage::class, ['recordId' => $stock->id])
        ->assertOk()
        ->assertSee('Purchase Receipt')
        ->assertSee('10');
});

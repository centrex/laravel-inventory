<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Transactions\ShipmentShowPage;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Product, ShipmentItem, Warehouse, WarehouseProduct};
use Livewire\Livewire;

it('reconciles shipping and extra-charge allocation exactly against the shipment totals', function (): void {
    $inventory = app(Inventory::class);

    $source = Warehouse::create([
        'code' => 'W-REC-1', 'name' => 'Source', 'country_code' => 'BD', 'currency' => 'BDT',
    ]);
    $destination = Warehouse::create([
        'code' => 'W-REC-2', 'name' => 'Destination', 'country_code' => 'BD', 'currency' => 'BDT',
    ]);

    $products = [];

    foreach (['A', 'B', 'C'] as $i => $code) {
        $product = Product::create([
            'sku'          => 'SKU-REC-' . $code, 'name' => 'Item ' . $code, 'unit' => 'pcs',
            'is_stockable' => true, 'weight_kg' => 1,
        ]);
        WarehouseProduct::create([
            'warehouse_id' => $source->id, 'product_id' => $product->id,
            'qty_on_hand'  => 50, 'qty_reserved' => 0, 'qty_in_transit' => 0,
            'wac_amount'   => 70 + $i * 13.37,
        ]);
        $products[] = $product;
    }

    // Three equal-weight items splitting a 10kg / 175.59 extra-charges pool three ways is the
    // classic case where independent per-line rounding used to drop a fraction of a cent: each
    // share rounds to 12.3332 (x3 = 36.9996) instead of the true 37.00 shipping_cost_amount.
    $shipment = $inventory->createInterWarehouseShipment([
        'from_warehouse_id'    => $source->id,
        'to_warehouse_id'      => $destination->id,
        'shipping_rate_per_kg' => 3.7,
        'customs_amount'       => 100.33,
        'handling_amount'      => 50.17,
        'insurance_amount'     => 25.09,
        'boxes'                => [
            [
                'box_code'           => 'BOX-1',
                'measured_weight_kg' => 10,
                'items'              => [
                    ['product_id' => $products[0]->id, 'qty_sent' => 1],
                    ['product_id' => $products[1]->id, 'qty_sent' => 1],
                    ['product_id' => $products[2]->id, 'qty_sent' => 1],
                ],
            ],
        ],
    ]);

    $shipment = $shipment->fresh(['items']);
    $items = ShipmentItem::query()->where('shipment_id', $shipment->id)->get();

    expect(round((float) $items->sum('shipping_allocated_amount'), 4))->toBe((float) $shipment->shipping_cost_amount)
        ->and(round((float) $items->sum('extra_charges_allocated_amount'), 4))->toBe((float) $shipment->extra_charges_total)
        ->and((float) $shipment->shipping_cost_amount)->toBe(37.0)
        ->and((float) $shipment->extra_charges_total)->toBe(175.59);
});

it('reconciles transfer shipping allocation exactly against the transfer total', function (): void {
    $inventory = app(Inventory::class);

    $source = Warehouse::create([
        'code' => 'W-REC-3', 'name' => 'Source', 'country_code' => 'BD', 'currency' => 'BDT',
    ]);
    $destination = Warehouse::create([
        'code' => 'W-REC-4', 'name' => 'Destination', 'country_code' => 'BD', 'currency' => 'BDT',
    ]);

    $products = [];

    foreach (['A', 'B', 'C'] as $code) {
        $product = Product::create([
            'sku'          => 'SKU-REC-T-' . $code, 'name' => 'Item ' . $code, 'unit' => 'pcs',
            'is_stockable' => true, 'weight_kg' => 1,
        ]);
        WarehouseProduct::create([
            'warehouse_id' => $source->id, 'product_id' => $product->id,
            'qty_on_hand'  => 50, 'qty_reserved' => 0, 'qty_in_transit' => 0, 'wac_amount' => 90,
        ]);
        $products[] = $product;
    }

    $transfer = $inventory->createTransfer([
        'from_warehouse_id'    => $source->id,
        'to_warehouse_id'      => $destination->id,
        'shipping_rate_per_kg' => 3.7,
        'boxes'                => [[
            'box_code'           => 'BOX-1',
            'measured_weight_kg' => 10,
            'items'              => [
                ['product_id' => $products[0]->id, 'qty_sent' => 1],
                ['product_id' => $products[1]->id, 'qty_sent' => 1],
                ['product_id' => $products[2]->id, 'qty_sent' => 1],
            ],
        ]],
    ]);

    $transfer = $transfer->fresh(['items']);

    expect((float) $transfer->items->sum('shipping_allocated_amount'))->toBe((float) $transfer->shipping_cost_amount)
        ->and((float) $transfer->shipping_cost_amount)->toBe(37.0);
});

it('shows the shipment lines reconciliation as balanced on the show page', function (): void {
    $inventory = app(Inventory::class);

    $source = Warehouse::create([
        'code' => 'W-REC-5', 'name' => 'Source', 'country_code' => 'BD', 'currency' => 'BDT',
    ]);
    $destination = Warehouse::create([
        'code' => 'W-REC-6', 'name' => 'Destination', 'country_code' => 'BD', 'currency' => 'BDT',
    ]);

    $products = [];

    foreach (['A', 'B', 'C'] as $i => $code) {
        $product = Product::create([
            'sku'          => 'SKU-REC-UI-' . $code, 'name' => 'Item ' . $code, 'unit' => 'pcs',
            'is_stockable' => true, 'weight_kg' => 1,
        ]);
        WarehouseProduct::create([
            'warehouse_id' => $source->id, 'product_id' => $product->id,
            'qty_on_hand'  => 50, 'qty_reserved' => 0, 'qty_in_transit' => 0,
            'wac_amount'   => 70 + $i * 13.37,
        ]);
        $products[] = $product;
    }

    $shipment = $inventory->createInterWarehouseShipment([
        'from_warehouse_id'    => $source->id,
        'to_warehouse_id'      => $destination->id,
        'shipping_rate_per_kg' => 3.7,
        'customs_amount'       => 100.33,
        'handling_amount'      => 50.17,
        'insurance_amount'     => 25.09,
        'boxes'                => [[
            'box_code'           => 'BOX-1',
            'measured_weight_kg' => 10,
            'items'              => [
                ['product_id' => $products[0]->id, 'qty_sent' => 1],
                ['product_id' => $products[1]->id, 'qty_sent' => 1],
                ['product_id' => $products[2]->id, 'qty_sent' => 1],
            ],
        ]],
    ]);

    Livewire::test(ShipmentShowPage::class, ['recordId' => $shipment->id])
        ->assertSee('Sum of lines')
        ->assertSee('37.00') // shipping_cost_amount and the summed shipping column both read this
        ->assertSee('175.59') // extra_charges_total and the summed extra-charges column
        ->assertSee('fully distributed across shipment lines')
        ->assertDontSee('unallocated');
});

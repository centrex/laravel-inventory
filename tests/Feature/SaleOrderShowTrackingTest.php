<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Transactions\SaleOrderShowPage;
use Centrex\Inventory\Models\{Customer, SaleOrder, Warehouse};
use Illuminate\Support\Facades\Http;

/**
 * Mirrors tests/Feature/CourierParcelTest.php's enableCourier()/makeCourierSaleOrder() but with
 * distinct names — pest -p runs test files across parallel worker processes, so top-level
 * functions defined in another test file aren't reliably available here.
 */
function enableCourierForTrackingModal(): void
{
    config()->set('cache.default', 'array');
    config()->set('inventory.erp.accounting.enabled', false);
    config()->set('inventory.courier.enabled', true);
    config()->set('inventory.courier.redx.sandbox', [
        'base_url'         => 'https://sandbox.redx.com.bd/v1.0.0-beta',
        'api_access_token' => 'redx-token',
    ]);
}

function makeTrackingModalSaleOrder(): SaleOrder
{
    $warehouse = Warehouse::create([
        'code'         => 'W-TRACK-' . uniqid(),
        'name'         => 'Tracking Modal Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $customer = Customer::create([
        'code'            => 'CUS-TRACK-' . uniqid(),
        'name'            => 'Tracking Modal Customer',
        'phone'           => '01700000000',
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'is_active'       => true,
    ]);

    return SaleOrder::create([
        'so_number'       => 'SO-TRACK-' . uniqid(),
        'document_type'   => 'order',
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customer->id,
        'price_tier_code' => 'b2c_retail',
        'currency'        => 'BDT',
        'exchange_rate'   => 1,
        'total_local'     => 100,
        'total_amount'    => 100,
        'status'          => 'processing',
        'ordered_at'      => now(),
    ]);
}

/** Injects metadata directly, same trick as SaleOrderDispatchInfoTest — laravel-model-data isn't wired as a path repo here. */
function makeTrackingModalComponent(SaleOrder $saleOrder, array $metadata = []): SaleOrderShowPage
{
    $component = new SaleOrderShowPage;
    $component->record = $saleOrder->load('customer');

    $ref = new ReflectionClass($component);
    $cacheProperty = $ref->getProperty('metadataCache');
    $cacheProperty->setAccessible(true);
    $cacheProperty->setValue($component, $metadata);

    return $component;
}

it('explains when the order has no courier-booked parcel to track', function (): void {
    enableCourierForTrackingModal();

    $component = makeTrackingModalComponent(makeTrackingModalSaleOrder());
    $component->openTrackingModal();

    expect($component->trackingModalOpen)->toBeTrue()
        ->and($component->trackingError)->toContain('no courier-booked parcel')
        ->and($component->parcelInfo)->toBe([])
        ->and($component->parcelTracking)->toBe([]);
});

it('fetches live parcel details and tracking history into the modal', function (): void {
    enableCourierForTrackingModal();

    Http::fake([
        'sandbox.redx.com.bd/v1.0.0-beta/parcel/info/*' => Http::response(['parcel' => [
            'tracking_id' => 'RX-SHOW-1', 'status' => 'delivery-in-progress', 'cash_collection_amount' => '400',
        ]]),
        'sandbox.redx.com.bd/v1.0.0-beta/parcel/track/*' => Http::response(['tracking' => [
            ['message_en' => 'Parcel created', 'time' => '2026-07-18 10:00:00'],
            ['message_en' => 'Picked up from store', 'time' => '2026-07-18 15:30:00'],
        ]]),
    ]);

    $component = makeTrackingModalComponent(makeTrackingModalSaleOrder(), [
        'carrier'             => 'Redx',
        'courier_provider'    => 'redx',
        'courier_environment' => 'sandbox',
        'tracking_number'     => 'RX-SHOW-1',
    ]);

    $component->openTrackingModal();

    expect($component->trackingModalOpen)->toBeTrue()
        ->and($component->trackingError)->toBe('')
        ->and($component->parcelInfo['status'])->toBe('delivery-in-progress')
        ->and($component->parcelTracking)->toHaveCount(2)
        ->and($component->parcelTracking[0]['message_en'])->toBe('Parcel created');
});

it('resets tracking state on close', function (): void {
    enableCourierForTrackingModal();

    $component = makeTrackingModalComponent(makeTrackingModalSaleOrder(), [
        'courier_provider' => 'redx',
        'tracking_number'  => 'RX-SHOW-2',
    ]);
    $component->openTrackingModal();
    $component->closeTrackingModal();

    expect($component->trackingModalOpen)->toBeFalse()
        ->and($component->parcelInfo)->toBe([])
        ->and($component->parcelTracking)->toBe([])
        ->and($component->trackingError)->toBe('');
});

it('resolves a public tracking link without needing the courier API to be enabled', function (): void {
    config()->set('inventory.courier.enabled', false);
    config()->set('courier.redx.tracking_link', 'https://example.test/track/{tracking_number}');

    $component = makeTrackingModalComponent(makeTrackingModalSaleOrder(), [
        'courier_provider' => 'redx',
        'tracking_number'  => 'RX-SHOW-3',
    ]);

    $ref = new ReflectionClass($component);
    $link = $ref->getMethod('resolveTrackingLink')->invoke($component);

    expect($link)->toBe('https://example.test/track/RX-SHOW-3');
});

it('returns no tracking link when the order has no booked parcel', function (): void {
    $component = makeTrackingModalComponent(makeTrackingModalSaleOrder());

    $ref = new ReflectionClass($component);
    $link = $ref->getMethod('resolveTrackingLink')->invoke($component);

    expect($link)->toBeNull();
});

<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Transactions\DispatchTerminalPage;
use Centrex\Inventory\Models\{Customer, Product, SaleOrder, SaleOrderItem, Warehouse, WarehouseProduct};
use Centrex\Inventory\Support\CourierIntegration;
use Illuminate\Support\Facades\{Gate, Http};

function makeCourierSaleOrder(string $status = 'processing', float $qtyReserved = 2): SaleOrder
{
    $warehouse = Warehouse::create([
        'code'         => 'W-COURIER-' . uniqid(),
        'name'         => 'Courier Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $customer = Customer::create([
        'code'              => 'CUS-COURIER-' . uniqid(),
        'name'              => 'Courier Customer',
        'organization_name' => 'Courier Org',
        'phone'             => '01700000000',
        'currency'          => 'BDT',
        'price_tier_code'   => 'b2c_retail',
        'is_active'         => true,
    ]);
    $product = Product::create([
        'sku'          => 'SKU-COURIER-' . uniqid(),
        'name'         => 'Courier Product',
        'unit'         => 'pcs',
        'weight_kg'    => 0.5,
        'is_stockable' => true,
    ]);

    WarehouseProduct::create([
        'warehouse_id'   => $warehouse->id,
        'product_id'     => $product->id,
        'qty_on_hand'    => 10,
        'qty_reserved'   => $qtyReserved,
        'qty_in_transit' => 0,
        'wac_amount'     => 100,
    ]);

    $saleOrder = SaleOrder::create([
        'so_number'       => 'SO-COURIER-' . uniqid(),
        'document_type'   => 'order',
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customer->id,
        'price_tier_code' => 'b2c_retail',
        'currency'        => 'BDT',
        'exchange_rate'   => 1,
        'total_local'     => 400,
        'total_amount'    => 400,
        'status'          => $status,
        'ordered_at'      => now(),
    ]);

    SaleOrderItem::create([
        'sale_order_id'     => $saleOrder->id,
        'product_id'        => $product->id,
        'price_tier_code'   => 'b2c_retail',
        'qty_ordered'       => 2,
        'unit_price_local'  => 200,
        'unit_price_amount' => 200,
        'line_total_local'  => 400,
        'line_total_amount' => 400,
    ]);

    return $saleOrder;
}

function enableCourier(): void
{
    // TokenManager caches the Pathao token via Cache::remember — the testing
    // database has no cache table, so use the array store.
    config()->set('cache.default', 'array');
    // These tests exercise the courier path, not the accounting bridge — with
    // laravel-accounting installed the bridge would otherwise fail fulfilment
    // on unseeded GL accounts.
    config()->set('inventory.erp.accounting.enabled', false);
    config()->set('inventory.courier.enabled', true);
    config()->set('inventory.courier.pathao.store_id', 'STORE-1');
    config()->set('inventory.courier.pathao.sandbox', [
        'base_url'      => 'https://courier-api-sandbox.pathao.com/',
        'client_id'     => 'cid',
        'client_secret' => 'secret',
        'username'      => 'merchant@example.com',
        'password'      => 'pass',
    ]);
    config()->set('inventory.courier.redx.sandbox', [
        'base_url'         => 'https://sandbox.redx.com.bd/v1.0.0-beta',
        'api_access_token' => 'redx-token',
    ]);
}

it('is disabled unless the courier config flag is on', function (): void {
    $saleOrder = makeCourierSaleOrder();

    expect(app(CourierIntegration::class)->enabled())->toBeFalse();

    app(CourierIntegration::class)->createParcel($saleOrder, 'pathao', 'sandbox', []);
})->throws(RuntimeException::class, 'Courier integration is not enabled.');

it('creates a pathao parcel including the token handshake', function (): void {
    enableCourier();

    Http::fake([
        'courier-api-sandbox.pathao.com/aladdin/api/v1/issue-token' => Http::response(['access_token' => 'tok-123']),
        'courier-api-sandbox.pathao.com/aladdin/api/v1/orders'      => Http::response(['data' => ['consignment_id' => 'DX-778899']]),
    ]);

    $saleOrder = makeCourierSaleOrder();

    $result = app(CourierIntegration::class)->createParcel($saleOrder, 'pathao', 'sandbox', [
        'recipient_name'    => 'Courier Org',
        'recipient_phone'   => '01700000000',
        'recipient_address' => 'House 1, Road 2, Dhaka',
        'recipient_city'    => 1,
        'recipient_zone'    => 25,
        'weight_kg'         => 1.5,
        'cod_amount'        => 400.0,
        'item_description'  => $saleOrder->so_number,
    ]);

    expect($result['tracking_number'])->toBe('DX-778899');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'aladdin/api/v1/orders')
        && $request['merchant_order_id'] === $saleOrder->so_number
        && $request['store_id'] === 'STORE-1'
        && $request['recipient_city'] === 1
        && $request['recipient_zone'] === 25
        && !isset($request['recipient_area'])
        && (float) $request['amount_to_collect'] === 400.0
        && $request->hasHeader('Authorization', 'Bearer tok-123'));
});

it('includes the recipient area on a pathao parcel when one is supplied', function (): void {
    enableCourier();

    Http::fake([
        'courier-api-sandbox.pathao.com/aladdin/api/v1/issue-token' => Http::response(['access_token' => 'tok-123']),
        'courier-api-sandbox.pathao.com/aladdin/api/v1/orders'      => Http::response(['data' => ['consignment_id' => 'DX-778900']]),
    ]);

    $saleOrder = makeCourierSaleOrder();

    app(CourierIntegration::class)->createParcel($saleOrder, 'pathao', 'sandbox', [
        'recipient_name'    => 'Courier Org',
        'recipient_phone'   => '01700000000',
        'recipient_address' => 'House 1, Road 2, Dhaka',
        'recipient_city'    => 1,
        'recipient_zone'    => 25,
        'recipient_area'    => 141,
        'weight_kg'         => 1.5,
        'cod_amount'        => 400.0,
    ]);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'aladdin/api/v1/orders')
        && $request['recipient_area'] === 141);
});

it('creates a redx parcel with weight converted to grams', function (): void {
    enableCourier();

    Http::fake([
        'sandbox.redx.com.bd/v1.0.0-beta/parcel' => Http::response(['tracking_id' => 'RX-445566']),
    ]);

    $saleOrder = makeCourierSaleOrder();

    $result = app(CourierIntegration::class)->createParcel($saleOrder, 'redx', 'sandbox', [
        'recipient_name'    => 'Courier Org',
        'recipient_phone'   => '01700000000',
        'recipient_address' => 'House 1, Road 2, Dhaka',
        'weight_kg'         => 1.5,
        'cod_amount'        => 400.0,
        'delivery_area_id'  => 42,
        'delivery_area'     => 'Dhanmondi',
        'pickup_store_id'   => 7,
    ]);

    expect($result['tracking_number'])->toBe('RX-445566');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'v1.0.0-beta/parcel')
        && $request['merchant_invoice_id'] === $saleOrder->so_number
        && $request['delivery_area_id'] === 42
        && $request['delivery_area'] === 'Dhanmondi'
        && $request['pickup_store_id'] === 7
        && $request['parcel_weight'] === 1500
        && $request->hasHeader('API-ACCESS-TOKEN', 'Bearer redx-token'));
});

it('looks up the redx area name when the caller does not supply one', function (): void {
    enableCourier();

    Http::fake([
        'sandbox.redx.com.bd/v1.0.0-beta/areas/42' => Http::response(['areas' => [['id' => 42, 'name' => 'Dhanmondi']]]),
        'sandbox.redx.com.bd/v1.0.0-beta/parcel'   => Http::response(['tracking_id' => 'RX-778800']),
    ]);

    $saleOrder = makeCourierSaleOrder();

    $result = app(CourierIntegration::class)->createParcel($saleOrder, 'redx', 'sandbox', [
        'recipient_name'    => 'Courier Org',
        'recipient_phone'   => '01700000000',
        'recipient_address' => 'House 1, Road 2, Dhaka',
        'weight_kg'         => 1.0,
        'cod_amount'        => 400.0,
        'delivery_area_id'  => 42,
    ]);

    expect($result['tracking_number'])->toBe('RX-778800');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'v1.0.0-beta/parcel')
        && $request['delivery_area'] === 'Dhanmondi');
});

it('rejects unsupported courier providers', function (): void {
    enableCourier();

    app(CourierIntegration::class)->createParcel(makeCourierSaleOrder(), 'sundarban', 'sandbox', []);
})->throws(InvalidArgumentException::class);

function parcelFormFor(string $provider): array
{
    return [
        'provider'          => $provider,
        'environment'       => 'sandbox',
        'recipient_name'    => 'Courier Org',
        'recipient_phone'   => '01700000000',
        'recipient_address' => 'House 1, Road 2, Dhaka',
        'weight_kg'         => '1.0',
        'cod_amount'        => '400',
        'item_description'  => '',
        'delivery_area_id'  => '',
        'pickup_store_id'   => '',
        'recipient_city'    => '',
        'recipient_zone'    => '',
        'recipient_area'    => '',
        'carried_by'        => '',
    ];
}

it('creates the parcel as a separate step without touching stock', function (): void {
    enableCourier();

    // Guest-friendly gate override: the closure must accept null for Gate::authorize
    // to pass without an authenticated user.
    Gate::define('inventory.courier.create-parcel', fn ($user = null): bool => true);

    Http::fake([
        'courier-api-sandbox.pathao.com/aladdin/api/v1/issue-token' => Http::response(['access_token' => 'tok-123']),
        'courier-api-sandbox.pathao.com/aladdin/api/v1/orders'      => Http::response(['data' => ['consignment_id' => 'DX-000111']]),
    ]);

    $saleOrder = makeCourierSaleOrder();

    $component = new DispatchTerminalPage();
    $component->parcelOrderId = $saleOrder->id;
    $component->parcelModalOpen = true;
    $component->parcelForm = array_merge(parcelFormFor('pathao'), [
        'recipient_city' => '1',
        'recipient_zone' => '25',
    ]);

    $component->createParcelForOrder();

    // Parcel booked, but the order has NOT been fulfilled — Ship is a later step.
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'aladdin/api/v1/orders'));
    expect($saleOrder->fresh()->status->value)->toBe('processing')
        ->and($component->parcelModalOpen)->toBeFalse()
        ->and(session()->get('status'))->toContain('DX-000111');
});

it('creates a hand-carry parcel without any courier api call', function (): void {
    // Hand-carry must work even with the courier API integration disabled.
    config()->set('inventory.erp.accounting.enabled', false);
    Gate::define('inventory.courier.create-parcel', fn ($user = null): bool => true);

    Http::fake();

    $saleOrder = makeCourierSaleOrder();

    $component = new DispatchTerminalPage();
    $component->parcelOrderId = $saleOrder->id;
    $component->parcelModalOpen = true;
    $component->parcelForm = array_merge(parcelFormFor('hand_carry'), ['carried_by' => 'Karim']);

    $component->createParcelForOrder();

    Http::assertNothingSent();
    expect($saleOrder->fresh()->status->value)->toBe('processing')
        ->and(session()->get('status'))->toContain('Hand Carry parcel CTRX-');
});

it('requires who carries a hand-carry parcel', function (): void {
    Gate::define('inventory.courier.create-parcel', fn ($user = null): bool => true);

    $saleOrder = makeCourierSaleOrder();

    $component = new DispatchTerminalPage();
    $component->parcelOrderId = $saleOrder->id;
    $component->parcelModalOpen = true;
    $component->parcelForm = parcelFormFor('hand_carry');

    try {
        $component->createParcelForOrder();
        $this->fail('Expected a validation exception for the missing carrier person.');
    } catch (Illuminate\Validation\ValidationException $exception) {
        expect($exception->errors())->toHaveKey('carried_by');
    }
});

it('requires a redx delivery area id before booking', function (): void {
    enableCourier();

    Gate::define('inventory.courier.create-parcel', fn ($user = null): bool => true);

    Http::fake();

    $saleOrder = makeCourierSaleOrder();

    $component = new DispatchTerminalPage();
    $component->parcelOrderId = $saleOrder->id;
    $component->parcelModalOpen = true;
    $component->parcelForm = parcelFormFor('redx');

    try {
        $component->createParcelForOrder();
        $this->fail('Expected a validation exception for the missing Redx delivery area id.');
    } catch (Illuminate\Validation\ValidationException $exception) {
        expect($exception->errors())->toHaveKey('delivery_area_id');
    }

    expect($saleOrder->fresh()->status->value)->toBe('processing');
    Http::assertNothingSent();
});

it('requires a pathao recipient city and zone before booking', function (): void {
    enableCourier();

    Gate::define('inventory.courier.create-parcel', fn ($user = null): bool => true);

    Http::fake();

    $saleOrder = makeCourierSaleOrder();

    $component = new DispatchTerminalPage();
    $component->parcelOrderId = $saleOrder->id;
    $component->parcelModalOpen = true;
    $component->parcelForm = parcelFormFor('pathao');

    try {
        $component->createParcelForOrder();
        $this->fail('Expected a validation exception for the missing Pathao city/zone.');
    } catch (Illuminate\Validation\ValidationException $exception) {
        expect($exception->errors())->toHaveKeys(['recipient_city', 'recipient_zone']);
    }

    expect($saleOrder->fresh()->status->value)->toBe('processing');
    Http::assertNothingSent();
});

it('offers create parcel before ship, and ship once a tracking number exists', function (): void {
    $saleOrder = makeCourierSaleOrder();

    $component = new DispatchTerminalPage();
    $ref = new ReflectionClass($component);
    $flow = $ref->getMethod('saleFlowFor')->invoke($component, $saleOrder);

    // Without the create-parcel gate → straight to Ship with wire:confirm
    $primary = $ref->getMethod('primaryActionFor')->invoke($component, $saleOrder, $flow, []);
    expect($primary['method'])->toBe('fulfillSaleOrderFlow')
        ->and($primary['label'])->toBe('Ship')
        ->and($primary['confirm'])->not->toBe('');

    // Gate granted + no parcel yet → Create Parcel modal opener (works even with the
    // courier API disabled, because hand-carry is always available)
    Gate::define('inventory.courier.create-parcel', fn ($user = null): bool => true);

    $primary = $ref->getMethod('primaryActionFor')->invoke($component, $saleOrder, $flow, []);
    expect($primary['method'])->toBe('openParcelModal')
        ->and($primary['label'])->toBe('Create Parcel')
        ->and($primary['confirm'])->toBe('');

    // Parcel already created (tracking number in metadata) → back to Ship
    $primary = $ref->getMethod('primaryActionFor')->invoke($component, $saleOrder, $flow, ['tracking_number' => 'DX-1']);
    expect($primary['method'])->toBe('fulfillSaleOrderFlow')
        ->and($primary['label'])->toBe('Ship');
});

it('prefills the parcel form from the customer when opening the modal', function (): void {
    config()->set('inventory.erp.accounting.enabled', false);
    Gate::define('inventory.courier.create-parcel', fn ($user = null): bool => true);

    $saleOrder = makeCourierSaleOrder();

    $component = new DispatchTerminalPage();
    $component->openParcelModal($saleOrder->id);

    expect($component->parcelModalOpen)->toBeTrue()
        ->and($component->parcelForm['recipient_name'])->toBe('Courier Org')
        ->and($component->parcelForm['recipient_phone'])->toBe('01700000000')
        // Courier API off in this environment → hand-carry preselected; no saved-address
        // package installed → address falls back to the (empty) dispatch metadata.
        ->and($component->parcelForm['provider'])->toBe('hand_carry')
        ->and($component->parcelForm['recipient_address'])->toBe('')
        ->and((float) $component->parcelForm['cod_amount'])->toBe(400.0);
});

it('loads redx areas and pickup stores when redx is selected in the modal', function (): void {
    enableCourier();
    config()->set('inventory.courier.default_provider', 'redx');
    config()->set('inventory.courier.redx.pickup_store_id', '3');
    Gate::define('inventory.courier.create-parcel', fn ($user = null): bool => true);

    Http::fake([
        'sandbox.redx.com.bd/v1.0.0-beta/areas' => Http::response(['areas' => [
            ['id' => 1, 'name' => 'Banani', 'district_name' => 'Dhaka', 'post_code' => 1213],
            ['id' => 2, 'name' => 'Agrabad', 'district_name' => 'Chattogram', 'post_code' => 4100],
        ]]),
        'sandbox.redx.com.bd/v1.0.0-beta/pickup/stores' => Http::response(['pickup_stores' => [
            ['id' => 3, 'name' => 'Main Store', 'area_id' => 7, 'area_name' => 'Banani'],
        ]]),
    ]);

    $saleOrder = makeCourierSaleOrder();

    $component = new DispatchTerminalPage();
    $component->openParcelModal($saleOrder->id);

    expect($component->parcelForm['provider'])->toBe('redx')
        ->and($component->parcelForm['pickup_store_id'])->toBe('3')
        ->and($component->redxAreas)->toHaveCount(2)
        ->and($component->redxPickupStores)->toHaveCount(1);

    // The search box narrows the delivery-area options
    $component->redxAreaSearch = 'chattogram';
    $ref = new ReflectionClass($component);
    $filtered = $ref->getMethod('filteredRedxAreas')->invoke($component);

    expect($filtered)->toHaveCount(1)
        ->and($filtered[0]['name'])->toBe('Agrabad');
});

it('cascades pathao city to zone to area selection in the modal', function (): void {
    enableCourier();
    config()->set('inventory.courier.default_provider', 'pathao');
    Gate::define('inventory.courier.create-parcel', fn ($user = null): bool => true);

    Http::fake([
        'courier-api-sandbox.pathao.com/aladdin/api/v1/issue-token' => Http::response(['access_token' => 'tok-123']),
        'courier-api-sandbox.pathao.com/aladdin/api/v1/city-list'   => Http::response(['data' => ['data' => [
            ['city_id' => 1, 'city_name' => 'Dhaka'],
        ]]]),
        'courier-api-sandbox.pathao.com/aladdin/api/v1/cities/1/zone-list' => Http::response(['data' => ['data' => [
            ['zone_id' => 25, 'zone_name' => 'Banani'],
        ]]]),
        'courier-api-sandbox.pathao.com/aladdin/api/v1/zones/25/area-list' => Http::response(['data' => ['data' => [
            ['area_id' => 141, 'area_name' => 'Road 11'],
        ]]]),
    ]);

    $saleOrder = makeCourierSaleOrder();

    $component = new DispatchTerminalPage();
    $component->openParcelModal($saleOrder->id);

    expect($component->parcelForm['provider'])->toBe('pathao')
        ->and($component->pathaoCities)->toHaveCount(1);

    $component->parcelForm['recipient_city'] = '1';
    $component->updatedParcelFormRecipientCity();

    expect($component->pathaoZones)->toHaveCount(1);

    $component->parcelForm['recipient_zone'] = '25';
    $component->updatedParcelFormRecipientZone();

    expect($component->pathaoAreas)->toHaveCount(1)
        ->and($component->pathaoAreas[0]['area_name'])->toBe('Road 11');
});

it('requires both redx areas before booking', function (): void {
    enableCourier();
    Gate::define('inventory.courier.create-parcel', fn ($user = null): bool => true);
    Http::fake();

    $saleOrder = makeCourierSaleOrder();

    $component = new DispatchTerminalPage();
    $component->parcelOrderId = $saleOrder->id;
    $component->parcelModalOpen = true;
    $component->parcelForm = parcelFormFor('redx');

    try {
        $component->createParcelForOrder();
        $this->fail('Expected a validation exception for the missing Redx areas.');
    } catch (Illuminate\Validation\ValidationException $exception) {
        expect($exception->errors())->toHaveKeys(['delivery_area_id', 'pickup_store_id']);
    }

    Http::assertNothingSent();
});

it('fetches live redx parcel details and tracking history', function (): void {
    enableCourier();

    Http::fake([
        'sandbox.redx.com.bd/v1.0.0-beta/parcel/info/*' => Http::response(['parcel' => [
            'tracking_id' => 'RX-TRACK-1', 'status' => 'delivery-in-progress', 'cash_collection_amount' => '400',
        ]]),
        'sandbox.redx.com.bd/v1.0.0-beta/parcel/track/*' => Http::response(['tracking' => [
            ['message_en' => 'Parcel created', 'time' => '2026-07-18 10:00:00'],
            ['message_en' => 'Picked up from store', 'time' => '2026-07-18 15:30:00'],
        ]]),
    ]);

    $details = app(CourierIntegration::class)->parcelDetails('redx', 'sandbox', 'RX-TRACK-1');

    expect($details['info']['status'])->toBe('delivery-in-progress')
        ->and($details['tracking'])->toHaveCount(2)
        ->and($details['tracking'][0]['message_en'])->toBe('Parcel created');
});

it('explains when an order has no courier parcel to track', function (): void {
    enableCourier();

    $saleOrder = makeCourierSaleOrder();

    $component = new DispatchTerminalPage();
    $component->openTrackingModal($saleOrder->id);

    expect($component->trackingModalOpen)->toBeTrue()
        ->and($component->trackingError)->toContain('no courier-booked parcel');
});

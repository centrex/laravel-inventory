<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Transactions\SaleOrderShowPage;
use Centrex\Inventory\Models\{Customer, SaleOrder, Warehouse};

/**
 * Rendering this component's Blade view hits the same pre-existing IconsManifest binding
 * error from the tallui vendor package noted in DispatchPrimaryActionTest — so this exercises
 * the private resolveDispatchInfo() directly via reflection instead of a full render.
 *
 * centrex/laravel-model-data (the polymorphic Data store resolveDispatchInfo() reads through
 * documentMetadata()) isn't wired up as a path repo for this package's dev environment, so it's
 * never actually installed here — every call would hit documentMetadata()'s class_exists() gate
 * and return []. To exercise the real key-mapping logic regardless, $metadata, when given,
 * is injected directly into the private $metadataCache property, which documentMetadata() reads
 * before ever touching the Data model.
 */
function resolveSaleOrderDispatchInfo(SaleOrder $saleOrder, ?array $metadata = null): ?array
{
    $component = new SaleOrderShowPage;
    $component->record = $saleOrder;

    $ref = new ReflectionClass($component);

    if ($metadata !== null) {
        $cacheProperty = $ref->getProperty('metadataCache');
        $cacheProperty->setAccessible(true);
        $cacheProperty->setValue($component, $metadata);
    }

    return $ref->getMethod('resolveDispatchInfo')->invoke($component);
}

function makeShowPageSaleOrder(): SaleOrder
{
    $warehouse = Warehouse::create([
        'code'         => 'W-DISP-' . uniqid(),
        'name'         => 'Dispatch Info Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $customer = Customer::create([
        'code'            => 'CUS-DISP-' . uniqid(),
        'name'            => 'Dispatch Info Customer',
        'currency'        => 'BDT',
        'price_tier_code' => 'b2c_retail',
        'is_active'       => true,
    ]);

    return SaleOrder::create([
        'so_number'       => 'SO-DISP-' . uniqid(),
        'document_type'   => 'order',
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customer->id,
        'price_tier_code' => 'b2c_retail',
        'currency'        => 'BDT',
        'exchange_rate'   => 1,
        'total_local'     => 100,
        'total_amount'    => 100,
        'status'          => 'confirmed',
        'ordered_at'      => now(),
    ]);
}

it('returns null when nothing has been dispatched yet', function (): void {
    $saleOrder = makeShowPageSaleOrder();

    expect(resolveSaleOrderDispatchInfo($saleOrder))->toBeNull();
});

it('surfaces the same carrier/tracking metadata the Dispatch Terminal writes', function (): void {
    $saleOrder = makeShowPageSaleOrder();

    $info = resolveSaleOrderDispatchInfo($saleOrder, [
        'carrier'             => 'Pathao',
        'tracking_number'     => 'CTRX-2607000001',
        'parcel_status'       => 'Out for delivery',
        'eta'                 => '02 Aug 2026',
        'location'            => 'Dhaka Hub',
        'dispatch_note'       => 'Fragile — handle with care.',
        'dispatched_by'       => 'Jane Doe',
        'dispatch_updated_at' => '2026-07-30 10:00:00',
    ]);

    expect($info)->not->toBeNull()
        ->and($info['carrier'])->toBe('Pathao')
        ->and($info['tracking_number'])->toBe('CTRX-2607000001')
        ->and($info['parcel_status'])->toBe('Out for delivery')
        ->and($info['eta'])->toBe('02 Aug 2026')
        ->and($info['location'])->toBe('Dhaka Hub')
        ->and($info['dispatch_note'])->toBe('Fragile — handle with care.')
        ->and($info['dispatched_by'])->toBe('Jane Doe')
        ->and($info['dispatch_updated_at'])->toBe('2026-07-30 10:00:00');
});

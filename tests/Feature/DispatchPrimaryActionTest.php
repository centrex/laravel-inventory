<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Livewire\Transactions\DispatchTerminalPage;
use Centrex\Inventory\Models\{Customer, Product, SaleOrder, SaleOrderItem, Warehouse};

/**
 * These call the private saleFlowFor()/primaryActionFor() logic directly via reflection
 * instead of Livewire::test()->assertSee(), because rendering this component's Blade view
 * in this environment hits an unrelated, pre-existing IconsManifest binding error from the
 * tallui vendor package (reproducible on main before this change too).
 */
function dispatchPrimaryAction(SaleOrder $saleOrder, array $meta): array
{
    $component = new DispatchTerminalPage();

    $ref = new ReflectionClass($component);

    $flow = $ref->getMethod('saleFlowFor')->invoke($component, $saleOrder);

    return $ref->getMethod('primaryActionFor')->invoke($component, $saleOrder, $flow, $meta);
}

function makeDispatchSaleOrder(string $status): SaleOrder
{
    $warehouse = Warehouse::create([
        'code'         => 'W-PRIM-' . uniqid(),
        'name'         => 'Primary Action Warehouse',
        'country_code' => 'BD',
        'currency'     => 'BDT',
    ]);
    $customer = Customer::create([
        'code'              => 'CUS-PRIM-' . uniqid(),
        'name'              => 'Primary Action Customer',
        'organization_name' => 'Primary Action Org',
        'currency'          => 'BDT',
        'price_tier_code'   => 'b2c_retail',
        'is_active'         => true,
    ]);
    $product = Product::create([
        'sku'          => 'SKU-PRIM-' . uniqid(),
        'name'         => 'Primary Action Product',
        'unit'         => 'pcs',
        'is_stockable' => true,
    ]);
    $saleOrder = SaleOrder::create([
        'so_number'       => 'SO-PRIM-' . uniqid(),
        'document_type'   => 'order',
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customer->id,
        'price_tier_code' => 'b2c_retail',
        'currency'        => 'BDT',
        'exchange_rate'   => 1,
        'total_local'     => 100,
        'total_amount'    => 100,
        'status'          => $status,
        'ordered_at'      => now(),
    ]);

    SaleOrderItem::create([
        'sale_order_id'     => $saleOrder->id,
        'product_id'        => $product->id,
        'price_tier_code'   => 'b2c_retail',
        'qty_ordered'       => 1,
        'unit_price_local'  => 100,
        'unit_price_amount' => 100,
        'line_total_local'  => 100,
        'line_total_amount' => 100,
    ]);

    return $saleOrder;
}

it('prioritizes fulfilling over dispatch tracking when both are technically eligible', function (): void {
    // Status "processing" means stock is reserved: canFulfill is true AND the order is
    // within DISPATCHABLE_STATUSES, so a naive implementation would offer both actions.
    $saleOrder = makeDispatchSaleOrder('processing');

    $primary = dispatchPrimaryAction($saleOrder, ['parcel_status' => 'Ready for courier']);

    expect($primary['type'])->toBe('fulfill');
});

it('offers dispatch tracking only once nothing is left to fulfill', function (): void {
    $saleOrder = makeDispatchSaleOrder('fulfilled');

    $primary = dispatchPrimaryAction($saleOrder, ['parcel_status' => 'Ready for courier']);

    expect($primary['type'])->toBe('dispatch')
        ->and($primary['label'])->toBe('Mark Dispatched');
});

it('offers a Confirm step for draft orders — ready but gated by permission', function (): void {
    $saleOrder = makeDispatchSaleOrder('draft');

    // No authenticated user with any grants — Gate::any() denies every ability, so the
    // step is "ready" (draft orders are shown and offered Confirm) but not "allowed".
    $primary = dispatchPrimaryAction($saleOrder, []);

    expect($primary['type'])->toBe('confirm')
        ->and($primary['allowed'])->toBeFalse()
        ->and($primary['need'])->toBe('confirm this order');
});

/**
 * Reads the "orders" collection straight off the render()-returned View's data, instead of
 * going through Livewire::test()->assertSee(), for the same IconsManifest reason noted above.
 */
function dispatchQueueOrderIds(string $status): Illuminate\Support\Collection
{
    $component = new DispatchTerminalPage();
    $component->status = $status;

    return collect($component->render()->getData()['orders']->items())->pluck('id');
}

it('includes draft orders in the default open queue', function (): void {
    $draft = makeDispatchSaleOrder('draft');
    $confirmed = makeDispatchSaleOrder('confirmed');

    $orderIds = dispatchQueueOrderIds('open');

    expect($orderIds)->toContain($draft->id)
        ->and($orderIds)->toContain($confirmed->id);
});

it('filters to only draft orders when the Draft status is selected', function (): void {
    $draft = makeDispatchSaleOrder('draft');
    $confirmed = makeDispatchSaleOrder('confirmed');

    $orderIds = dispatchQueueOrderIds('draft');

    expect($orderIds)->toContain($draft->id)
        ->and($orderIds)->not->toContain($confirmed->id);
});

<?php

declare(strict_types = 1);

use Centrex\Inventory\Models\{Customer, SaleOrder, SaleReturn, SaleReturnItem, Warehouse};
use Centrex\Inventory\Support\SalesOrderProfitSummary;

function makeProfitSummaryOrder(string $suffix, float $totalAmount, float $cogsAmount, string $status): SaleOrder
{
    $warehouse = Warehouse::query()->first() ?? Warehouse::create([
        'code' => 'W-PS', 'name' => 'Main', 'country_code' => 'BD', 'currency' => 'BDT',
    ]);
    $customer = Customer::create(['code' => "C-PS-{$suffix}", 'name' => 'Acme', 'currency' => 'BDT']);

    return SaleOrder::create([
        'so_number'       => "SO-PS-{$suffix}",
        'document_type'   => 'order',
        'warehouse_id'    => $warehouse->id,
        'customer_id'     => $customer->id,
        'price_tier_code' => 'BASE',
        'status'          => $status,
        'currency'        => 'BDT',
        'exchange_rate'   => 1,
        'total_amount'    => $totalAmount,
        'cogs_amount'     => $cogsAmount,
        'ordered_at'      => today(),
    ]);
}

it('computes margin only over orders that have actually been costed', function (): void {
    // Fulfilled: true 30% margin (revenue 1000, cogs 700).
    makeProfitSummaryOrder('A', 1000, 700, 'fulfilled');
    // Confirmed but not yet shipped: full revenue booked, no cost yet (cogs_amount still 0).
    // Blending this in at face value would read as ~100% margin for this order.
    makeProfitSummaryOrder('B', 1000, 0, 'confirmed');

    $orders = SaleOrder::all();
    $summary = app(SalesOrderProfitSummary::class)->summarize($orders);

    expect($summary['orders_count'])->toBe(2)
        // Revenue is intentionally the full "orders placed" total, unfulfilled included.
        ->and($summary['revenue'])->toBe(2000.0)
        // But gross_profit/gross_margin_pct are computed only over the costed order.
        ->and($summary['gross_profit'])->toBe(300.0)
        ->and($summary['gross_margin_pct'])->toBe(30.0);
});

it('returns a null margin when no order in the set has been costed yet', function (): void {
    makeProfitSummaryOrder('C', 500, 0, 'confirmed');
    makeProfitSummaryOrder('D', 500, 0, 'processing');

    $summary = app(SalesOrderProfitSummary::class)->summarize(SaleOrder::all());

    expect($summary['revenue'])->toBe(1000.0)
        ->and($summary['gross_profit'])->toBe(0.0)
        ->and($summary['gross_margin_pct'])->toBeNull();
});

it('still includes a partially fulfilled order once any cogs_amount has posted', function (): void {
    // cogs_amount > 0 is enough to count as "costed", even though only half of this order's
    // qty has actually shipped — a known remaining approximation (full order revenue vs.
    // partial cost), separate from the confirmed/processing $0-cogs case fixed here.
    makeProfitSummaryOrder('E', 1000, 350, 'partial');

    $summary = app(SalesOrderProfitSummary::class)->summarize(SaleOrder::all());

    expect($summary['revenue'])->toBe(1000.0)
        ->and($summary['gross_profit'])->toBe(650.0);
});

it('nets out the margin on posted customer returns against the same order', function (): void {
    // Fulfilled: revenue 1000, cogs 700 -> 300 margin before any return.
    $order = makeProfitSummaryOrder('F', 1000, 700, 'fulfilled');

    // Half the units come back: qty 1 @ price 500 / cost 350 -> 150 margin returned.
    $saleReturn = SaleReturn::create([
        'return_number' => 'SRT-PS-F',
        'sale_order_id' => $order->id,
        'warehouse_id'  => $order->warehouse_id,
        'customer_id'   => $order->customer_id,
        'status'        => 'posted',
        'returned_at'   => today(),
    ]);
    SaleReturnItem::create([
        'sale_return_id'    => $saleReturn->id,
        'product_id'        => 1,
        'qty_returned'      => 1,
        'unit_price_amount' => 500,
        'unit_cost_amount'  => 350,
        'line_total_amount' => 500,
    ]);

    $summary = app(SalesOrderProfitSummary::class)->summarize(SaleOrder::all());

    expect($summary['gross_profit'])->toBe(150.0);
});

it('ignores a draft (not yet posted) customer return', function (): void {
    $order = makeProfitSummaryOrder('G', 1000, 700, 'fulfilled');

    $saleReturn = SaleReturn::create([
        'return_number' => 'SRT-PS-G',
        'sale_order_id' => $order->id,
        'warehouse_id'  => $order->warehouse_id,
        'customer_id'   => $order->customer_id,
        'status'        => 'draft',
        'returned_at'   => today(),
    ]);
    SaleReturnItem::create([
        'sale_return_id'    => $saleReturn->id,
        'product_id'        => 1,
        'qty_returned'      => 1,
        'unit_price_amount' => 500,
        'unit_cost_amount'  => 350,
        'line_total_amount' => 500,
    ]);

    $summary = app(SalesOrderProfitSummary::class)->summarize(SaleOrder::all());

    expect($summary['gross_profit'])->toBe(300.0);
});

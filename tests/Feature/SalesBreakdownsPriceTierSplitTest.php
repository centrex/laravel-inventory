<?php

declare(strict_types = 1);

use Centrex\Inventory\Models\SaleOrder;
use Centrex\Inventory\Support\SalesBreakdowns;

/**
 * Coverage for the per-employee price-tier split added to SalesBreakdowns::byEmployee() —
 * used by the Sales Report's "Sales by Employee" card (InventorySalesStatisticsCard) to show,
 * per employee, how many orders and how much revenue came from each price tier.
 *
 * sales_executive_id/created_by are left null (falls back to the 'unassigned' bucket) rather
 * than a real user id, since this package's isolated test env has no `users` table — the
 * grouping/aggregation logic under test doesn't depend on employee identity resolution.
 */
it('byEmployee groups each employee\'s orders by price tier with order count and revenue', function (): void {
    $orders = collect([
        new SaleOrder(['price_tier_code' => 'b2c_retail', 'total_amount' => 100, 'cogs_amount' => 0]),
        new SaleOrder(['price_tier_code' => 'b2c_retail', 'total_amount' => 50, 'cogs_amount' => 0]),
        new SaleOrder(['price_tier_code' => 'b2b_wholesale', 'total_amount' => 300, 'cogs_amount' => 0]),
    ]);

    $result = SalesBreakdowns::byEmployee($orders);

    expect($result)->toHaveCount(1);

    $employee = $result[0];
    expect($employee['orders_count'])->toBe(3)
        ->and($employee['revenue'])->toBe(450.0)
        ->and($employee['by_price_tier'])->toHaveCount(2);

    $tiers = collect($employee['by_price_tier'])->keyBy('code');

    expect($tiers['b2b_wholesale']['orders_count'])->toBe(1)
        ->and($tiers['b2b_wholesale']['revenue'])->toBe(300.0)
        ->and($tiers['b2c_retail']['orders_count'])->toBe(2)
        ->and($tiers['b2c_retail']['revenue'])->toBe(150.0);

    // Sorted by revenue descending, same convention as byPriceTier() itself
    expect($employee['by_price_tier'][0]['code'])->toBe('b2b_wholesale');
});

it('byEmployee tier split treats a null price tier as its own "Unassigned" bucket', function (): void {
    $orders = collect([
        new SaleOrder(['price_tier_code' => null, 'total_amount' => 75, 'cogs_amount' => 0]),
    ]);

    $result = SalesBreakdowns::byEmployee($orders);

    expect($result[0]['by_price_tier'])->toHaveCount(1)
        ->and($result[0]['by_price_tier'][0]['label'])->toBe('Unassigned')
        ->and($result[0]['by_price_tier'][0]['revenue'])->toBe(75.0);
});

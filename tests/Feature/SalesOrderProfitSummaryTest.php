<?php

declare(strict_types = 1);

use Centrex\Accounting\Facades\Accounting;
use Centrex\Accounting\Models\{Account, Expense, Invoice};
use Centrex\Accounting\Models\Customer as AccountingCustomer;
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

/**
 * Links a fresh Invoice to $order (so it counts as $order's accounting_invoice_id) and records
 * a discount Expense against it, dated $expenseDate, on account 6130 — same shape as
 * ErpIntegration's real invoice-discount records.
 */
function makeProfitSummaryDiscount(SaleOrder $order, float $amount, Illuminate\Support\Carbon $expenseDate): void
{
    $accountingCustomer = AccountingCustomer::create([
        'code' => 'AC-' . $order->so_number, 'name' => 'Acme', 'currency' => 'BDT',
    ]);
    $invoice = Invoice::create([
        'customer_id'    => $accountingCustomer->id,
        'invoice_number' => 'INV-PS-' . $order->so_number,
        'invoice_date'   => $order->ordered_at,
        'due_date'       => $order->ordered_at,
        'currency'       => 'BDT',
        'subtotal'       => $order->total_amount,
        'tax_amount'     => 0,
        'total'          => $order->total_amount,
    ]);
    $order->update(['accounting_invoice_id' => $invoice->id]);

    $discountAccount = Account::where('code', '6130')->first();

    Expense::create([
        'chargeable_type' => Invoice::class,
        'chargeable_id'   => $invoice->id,
        'account_id'      => $discountAccount->id,
        'expense_date'    => $expenseDate,
        'subtotal'        => $amount,
        'tax_amount'      => 0,
        'total'           => $amount,
        'paid_amount'     => 0,
        'currency'        => 'BDT',
        'status'          => 'paid',
        'payment_method'  => 'cash',
        'reference'       => $invoice->invoice_number,
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

it('attributes a return to the month it was processed in, not the month its order was placed, when a window is given', function (): void {
    // Order placed and fulfilled back in a prior month.
    $order = makeProfitSummaryOrder('H', 1000, 700, 'fulfilled');
    $order->forceFill(['ordered_at' => today()->subMonths(2)])->save();

    // But the customer returns it THIS month.
    $saleReturn = SaleReturn::create([
        'return_number' => 'SRT-PS-H',
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

    // Without a window: unchanged pre-existing behavior — the return doesn't count because
    // $order isn't in $thisMonthOrders (it was placed two months ago).
    $thisMonthOrders = SaleOrder::whereBetween('ordered_at', [today()->startOfMonth(), today()->endOfDay()])->get();
    expect($thisMonthOrders)->toHaveCount(0);
    $withoutWindow = app(SalesOrderProfitSummary::class)->summarize($thisMonthOrders);
    expect($withoutWindow['gross_profit'])->toBe(0.0);

    // With a window covering this month, and $order in the eligible set (unbounded by date):
    // the return is picked up this month even though its order belongs to a prior month —
    // matching how the accounting ledger dates the reversal by returned_at. No order was
    // placed this month, so costedRevenue/cogs are both 0 — the windowed return's own
    // revenue/cost swing is all that's left: -500 (revenue given back) + 350 (cost
    // recovered) = -150.
    $allOrderIds = SaleOrder::pluck('id')->all();
    $withWindow = app(SalesOrderProfitSummary::class)->summarize(
        $thisMonthOrders,
        $allOrderIds,
        [today()->startOfMonth(), today()->endOfDay()],
    );
    expect($withWindow['gross_profit'])->toBe(-150.0);
});

it('does not pick up a return whose returned_at falls outside the given window, even if the order is eligible', function (): void {
    $order = makeProfitSummaryOrder('J', 1000, 700, 'fulfilled');

    $saleReturn = SaleReturn::create([
        'return_number' => 'SRT-PS-J',
        'sale_order_id' => $order->id,
        'warehouse_id'  => $order->warehouse_id,
        'customer_id'   => $order->customer_id,
        'status'        => 'posted',
        'returned_at'   => today()->subMonths(3),
    ]);
    SaleReturnItem::create([
        'sale_return_id'    => $saleReturn->id,
        'product_id'        => 1,
        'qty_returned'      => 1,
        'unit_price_amount' => 500,
        'unit_cost_amount'  => 350,
        'line_total_amount' => 500,
    ]);

    $orders = SaleOrder::all();
    $allOrderIds = $orders->pluck('id')->all();

    $summary = app(SalesOrderProfitSummary::class)->summarize(
        $orders,
        $allOrderIds,
        [today()->startOfMonth(), today()->endOfDay()],
    );

    // The return happened 3 months ago — outside this month's window — so this month sees the
    // order's full, un-reversed margin.
    expect($summary['gross_profit'])->toBe(300.0);
});

it('attributes a discount to the month it was processed in, not the month its order was placed, when a window is given', function (): void {
    Accounting::initializeChartOfAccounts();

    // Order placed and fulfilled two months ago.
    $order = makeProfitSummaryOrder('K', 1000, 700, 'fulfilled');
    $order->forceFill(['ordered_at' => today()->subMonths(2)])->save();

    // But the discount is applied to its invoice THIS month.
    makeProfitSummaryDiscount($order, 80.0, today());

    $thisMonthOrders = SaleOrder::whereBetween('ordered_at', [today()->startOfMonth(), today()->endOfDay()])->get();
    $allOrderIds = SaleOrder::pluck('id')->all();
    $allInvoiceIds = SaleOrder::whereNotNull('accounting_invoice_id')->pluck('accounting_invoice_id')->all();

    // Without a window: unchanged pre-existing behavior — the discount doesn't count because
    // $order (and its invoice) isn't in this month's own order set.
    $withoutWindow = app(SalesOrderProfitSummary::class)->summarize($thisMonthOrders);
    expect($withoutWindow['gross_profit'])->toBe(0.0);

    // With a window covering this month, and the invoice in the eligible set (unbounded by
    // date): the discount is picked up this month even though its order belongs to a prior
    // month — matching how the ledger dates the discount by its own expense_date. No order was
    // placed this month, so the discount's own amount is all that shows: -80.
    $withWindow = app(SalesOrderProfitSummary::class)->summarize(
        $thisMonthOrders,
        $allOrderIds,
        [today()->startOfMonth(), today()->endOfDay()],
        $allInvoiceIds,
    );
    expect($withWindow['gross_profit'])->toBe(-80.0);
});

it('does not pick up a discount whose expense_date falls outside the given window', function (): void {
    Accounting::initializeChartOfAccounts();

    $order = makeProfitSummaryOrder('L', 1000, 700, 'fulfilled');
    makeProfitSummaryDiscount($order, 80.0, today()->subMonths(3));

    $orders = SaleOrder::all();
    $allOrderIds = $orders->pluck('id')->all();
    $allInvoiceIds = SaleOrder::whereNotNull('accounting_invoice_id')->pluck('accounting_invoice_id')->all();

    $summary = app(SalesOrderProfitSummary::class)->summarize(
        $orders,
        $allOrderIds,
        [today()->startOfMonth(), today()->endOfDay()],
        $allInvoiceIds,
    );

    // The discount was applied 3 months ago — outside this month's window — so this month sees
    // the order's full, undiscounted margin.
    expect($summary['gross_profit'])->toBe(300.0);
});

it('never windows charges — they stay scoped to the order set even when a window is given', function (): void {
    Accounting::initializeChartOfAccounts();

    // Order placed two months ago, with a delivery charge posted against its invoice THIS
    // month — the same shape as the discount test above, but for a charge code (6310).
    $order = makeProfitSummaryOrder('M', 1000, 700, 'fulfilled');
    $order->forceFill(['ordered_at' => today()->subMonths(2)])->save();

    $accountingCustomer = AccountingCustomer::create(['code' => 'AC-M', 'name' => 'Acme', 'currency' => 'BDT']);
    $invoice = Invoice::create([
        'customer_id'    => $accountingCustomer->id,
        'invoice_number' => 'INV-PS-M', 'invoice_date' => $order->ordered_at, 'due_date' => $order->ordered_at,
        'currency'       => 'BDT', 'subtotal' => 1000, 'tax_amount' => 0, 'total' => 1000,
    ]);
    $order->update(['accounting_invoice_id' => $invoice->id]);
    $chargeAccount = Account::where('code', '6310')->first();
    Expense::create([
        'chargeable_type' => Invoice::class, 'chargeable_id' => $invoice->id, 'account_id' => $chargeAccount->id,
        'expense_date'    => today(), 'subtotal' => 50, 'tax_amount' => 0, 'total' => 50, 'paid_amount' => 0,
        'currency'        => 'BDT', 'status' => 'paid', 'payment_method' => 'cash', 'reference' => $invoice->invoice_number,
    ]);

    $thisMonthOrders = SaleOrder::whereBetween('ordered_at', [today()->startOfMonth(), today()->endOfDay()])->get();
    $allOrderIds = SaleOrder::pluck('id')->all();
    $allInvoiceIds = SaleOrder::whereNotNull('accounting_invoice_id')->pluck('accounting_invoice_id')->all();

    $summary = app(SalesOrderProfitSummary::class)->summarize(
        $thisMonthOrders,
        $allOrderIds,
        [today()->startOfMonth(), today()->endOfDay()],
        $allInvoiceIds,
    );

    // No orders placed this month, and charges are never windowed — so the charge posted this
    // month against a two-month-old order still doesn't count here.
    expect($summary['gross_profit'])->toBe(0.0);
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

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Support;

use Centrex\Inventory\Models\{SaleReturn, SaleReturnItem};
use Illuminate\Support\Collection;

/**
 * Real, net-of-charges profit for a set of sale orders: revenue minus cost of goods,
 * sales discounts (Invoice::AR_REDUCING_ACCOUNT_CODES — 6130-6133: sales/early-payment/
 * volume/promotional discount), delivery/return charges (accounts 6310/6320/6330/6340)
 * recorded against each order's posted invoice, and posted customer returns against those
 * orders (SaleReturn/SaleReturnItem — the same qty*price/qty*cost basis ErpIntegration uses
 * to reverse COGS and issue the AR credit memo). Falls back to 0 for accounting-sourced
 * deductions when accounting isn't installed. Exposed as gross_profit/gross_margin_pct for
 * display.
 *
 * Shared by the dashboard's Sales Order Trend and Sales by Employee cards so both reduce
 * revenue/COGS/discounts/charges/returns to gross_profit the same way.
 */
final class SalesOrderProfitSummary
{
    /**
     * @param  Collection<int, \Centrex\Inventory\Models\SaleOrder>  $orders
     * @param  array<int, int>|null  $returnEligibleOrderIds  the full set of order ids a
     *                                                        return is allowed to be attributed against (typically every order visible under
     *                                                        the caller's team-scope, unbounded by date) — required whenever $returnWindow is
     *                                                        given; defaults to $orders' own ids, which reproduces the pre-existing
     *                                                        order-month-scoped behavior for callers that don't pass a window (SalesBreakdowns'
     *                                                        employee/price-tier group-bys, where a return must stay attributed to the group
     *                                                        it's grouped by).
     * @param  array{0: mixed, 1: mixed}|null  $returnWindow  when given, a return counts toward
     *                                                        this summary if its OWN `returned_at` falls in [start, end] — matching how the
     *                                                        accounting ledger recognizes a return in the period it happened — instead of
     *                                                        requiring its original order to be one of $orders. Pass this from any caller whose
     *                                                        result gets compared against the Income Statement (see InventorySalesTrendCard);
     *                                                        leave null to keep a return tied to its order's own month.
     * @return array{orders_count: int, revenue: float, gross_profit: float, gross_margin_pct: ?float}
     */
    public function summarize(Collection $orders, ?array $returnEligibleOrderIds = null, ?array $returnWindow = null): array
    {
        $revenue = (float) $orders->sum('total_amount');

        // cogs_amount only gets populated by fulfillSaleOrder() — it's still 0 on a confirmed/
        // processing order (nothing shipped yet) and only partially reflects a PARTIAL order's
        // full total_amount. Status isn't a safe proxy for "has this been costed" either: SHIPPED
        // is a parallel courier-tracking state that doesn't guarantee fulfillSaleOrder() has run
        // (see SaleOrderStatus). Blending an order's full revenue against a $0/partial cost basis
        // would overstate margin — sometimes drastically — so gross_profit/gross_margin_pct are
        // computed only over the subset that's actually been costed. `revenue` above is
        // deliberately left as every order in $orders (an "orders placed" figure), so it will not
        // arithmetically reconcile against gross_profit — that's intentional, not a bug.
        $costedOrders = $orders->filter(static fn ($order): bool => (float) $order->cogs_amount > 0.0);
        $costedRevenue = (float) $costedOrders->sum('total_amount');
        $cogs = (float) $costedOrders->sum('cogs_amount');

        $invoiceIds = $costedOrders->pluck('accounting_invoice_id')->filter()->unique()->values()->map(static fn ($id): int => (int) $id)->all();
        $deductions = $this->deductions($invoiceIds);

        $orderIds = $costedOrders->pluck('id')->filter()->unique()->values()->map(static fn ($id): int => (int) $id)->all();
        $returns = $this->returnAdjustments($returnEligibleOrderIds ?? $orderIds, $returnWindow);

        $grossProfit = $costedRevenue - $cogs - $deductions['discount'] - $deductions['charges'] - $returns['revenue'] + $returns['cost'];

        return [
            'orders_count'     => $orders->count(),
            'revenue'          => $revenue,
            'gross_profit'     => $grossProfit,
            'gross_margin_pct' => $costedRevenue != 0.0 ? round($grossProfit / $costedRevenue * 100, 1) : null,
        ];
    }

    /**
     * @param  array<int, int>  $invoiceIds
     * @return array{discount: float, charges: float}
     */
    private function deductions(array $invoiceIds): array
    {
        $expenseClass = 'Centrex\\Accounting\\Models\\Expense';
        $invoiceClass = 'Centrex\\Accounting\\Models\\Invoice';

        if ($invoiceIds === [] || !class_exists($expenseClass) || !class_exists($invoiceClass)) {
            return ['discount' => 0.0, 'charges' => 0.0];
        }

        $discountCodes = $invoiceClass::AR_REDUCING_ACCOUNT_CODES;
        $chargeCodes = ['6310', '6320', '6330', '6340'];

        $expenses = $expenseClass::query()
            ->where('chargeable_type', $invoiceClass)
            ->whereIn('chargeable_id', $invoiceIds)
            ->whereHas('account', function ($query) use ($discountCodes, $chargeCodes): void {
                $query->whereIn('code', [...$discountCodes, ...$chargeCodes]);
            })
            ->with('account:id,code')
            ->get(['id', 'total', 'account_id']);

        return [
            'discount' => (float) $expenses->filter(static fn ($expense): bool => in_array($expense->account?->code, $discountCodes, true))->sum('total'),
            'charges'  => (float) $expenses->filter(static fn ($expense): bool => in_array($expense->account?->code, $chargeCodes, true))->sum('total'),
        ];
    }

    /**
     * Revenue and cost taken back out by posted customer returns against these orders, on the
     * same qty*unit_price / qty*unit_cost basis ErpIntegration::postSaleReturn()/
     * issueSaleReturnCreditMemo() use — so an order whose units came back reduces gross_profit
     * by the margin that was originally recognized on those units.
     *
     * Without $window: a return counts if its order is in $orderIds, regardless of which month
     * the return itself was processed in — the original (and still correct, for a
     * group-attributed summary) behavior.
     *
     * With $window: $orderIds is treated as "which orders are allowed to attribute here at
     * all" (typically unbounded by date) and a return counts if its OWN `returned_at` falls in
     * the window instead — matching ErpIntegration's ledger postings, which date a return's
     * reversal by when it happened, not by the original order's month.
     *
     * @param  array<int, int>  $orderIds
     * @param  array{0: mixed, 1: mixed}|null  $window
     * @return array{revenue: float, cost: float}
     */
    private function returnAdjustments(array $orderIds, ?array $window = null): array
    {
        if ($orderIds === []) {
            return ['revenue' => 0.0, 'cost' => 0.0];
        }

        $query = SaleReturn::query()
            ->where('status', 'posted')
            ->whereIn('sale_order_id', $orderIds);

        if ($window !== null) {
            $query->whereBetween('returned_at', $window);
        }

        $returnIds = $query->pluck('id');

        if ($returnIds->isEmpty()) {
            return ['revenue' => 0.0, 'cost' => 0.0];
        }

        $totals = SaleReturnItem::query()
            ->whereIn('sale_return_id', $returnIds)
            ->selectRaw('SUM(qty_returned * unit_price_amount) as revenue, SUM(qty_returned * unit_cost_amount) as cost')
            ->first();

        return [
            'revenue' => (float) ($totals->revenue ?? 0),
            'cost'    => (float) ($totals->cost ?? 0),
        ];
    }
}

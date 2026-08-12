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
     * @return array{orders_count: int, revenue: float, gross_profit: float, gross_margin_pct: ?float}
     */
    public function summarize(Collection $orders): array
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
        $returns = $this->returnAdjustments($orderIds);

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
     * by the margin that was originally recognized on those units, regardless of which month
     * the return itself was processed in.
     *
     * @param  array<int, int>  $orderIds
     * @return array{revenue: float, cost: float}
     */
    private function returnAdjustments(array $orderIds): array
    {
        if ($orderIds === []) {
            return ['revenue' => 0.0, 'cost' => 0.0];
        }

        $returnIds = SaleReturn::query()
            ->where('status', 'posted')
            ->whereIn('sale_order_id', $orderIds)
            ->pluck('id');

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

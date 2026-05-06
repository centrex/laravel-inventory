<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Support;

use Centrex\Inventory\Enums\SaleOrderStatus;
use Centrex\Inventory\Models\{CommercialTeamMember, SaleOrder};
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

final class SalesTargetCalculator
{
    /**
     * Build the dashboard sales target from recent sales, posted expenses, and payroll.
     *
     * Optional packages are intentionally discovered at runtime. Inventory can be
     * installed without accounting or payroll, and the dashboard should still render.
     */
    public function calculate(
        int $lookbackDays = 90,
        int $targetDays = 30,
        ?float $expectedGrossMarginPct = null,
        float $desiredNetMarginPct = 10.0,
        float $growthPct = 0.0,
        ?float $expenseAllocationPct = null,
    ): array {
        $lookbackDays = max(7, min(730, $lookbackDays));
        $targetDays = max(1, min(366, $targetDays));
        $desiredNetMarginPct = max(0.0, min(80.0, $desiredNetMarginPct));
        $growthPct = max(0.0, min(200.0, $growthPct));

        $historyEnd = now()->endOfDay();
        $historyStart = now()->copy()->subDays($lookbackDays - 1)->startOfDay();
        $observedDays = max(1, (int) floor($historyStart->diffInDays($historyEnd)) + 1);

        $orders = $this->salesOrders($historyStart, $historyEnd);
        $history = $this->salesHistory($orders, $observedDays);

        $expectedGrossMarginPct = $expectedGrossMarginPct === null
            ? ($history['gross_margin_pct'] > 0 ? $history['gross_margin_pct'] : 25.0)
            : max(1.0, min(95.0, $expectedGrossMarginPct));

        $visibleSalesUserIds = CommercialTeamAccess::visibleUserIds('sales');
        $salesTeamUserIds = $this->salesTeamUserIds($visibleSalesUserIds);
        $allSalesTeamCount = $this->salesTeamUserIds(null)->count();
        $salesTeamCount = $salesTeamUserIds->count();
        $expenseAllocationPct = $this->expenseAllocationPct($expenseAllocationPct, $salesTeamCount, $allSalesTeamCount);

        $historyExpense = $this->accountingExpenseTotal($historyStart, $historyEnd);
        $historyPayroll = $this->salesPayrollTotal($salesTeamUserIds, $historyStart, $historyEnd, $observedDays);
        $target = $this->targetFigures(
            historyRevenue: $history['revenue'],
            historyExpense: $historyExpense,
            historyPayroll: $historyPayroll,
            observedDays: $observedDays,
            targetDays: $targetDays,
            expectedGrossMarginPct: $expectedGrossMarginPct,
            desiredNetMarginPct: $desiredNetMarginPct,
            growthPct: $growthPct,
            expenseAllocationPct: $expenseAllocationPct,
        );

        return [
            'window' => [
                'history_start' => $historyStart->toDateString(),
                'history_end'   => $historyEnd->toDateString(),
                'lookback_days' => $lookbackDays,
                'target_days'   => $targetDays,
            ],
            'inputs' => [
                'expected_gross_margin_pct'   => round($expectedGrossMarginPct, 2),
                'desired_net_margin_pct'      => round($desiredNetMarginPct, 2),
                'growth_pct'                  => round($growthPct, 2),
                'expense_allocation_pct'      => round($expenseAllocationPct, 2),
                'auto_expense_allocation_pct' => $this->autoExpenseAllocationPct($salesTeamCount, $allSalesTeamCount),
            ],
            'history' => [
                ...$history,
                'expense'                  => round($historyExpense, 2),
                'allocated_expense'        => round($historyExpense * ($expenseAllocationPct / 100), 2),
                'payroll'                  => round($historyPayroll, 2),
                'sales_team_members_count' => $salesTeamCount,
                'all_sales_team_count'     => $allSalesTeamCount,
            ],
            'target'       => $target,
            'availability' => [
                'accounting_expenses' => $this->modelTableReady('Centrex\\Accounting\\Models\\Expense'),
                'payroll'             => $this->modelTableReady('Centrex\\Payroll\\Models\\PayrollEntryLine')
                    && $this->modelTableReady('Centrex\\Payroll\\Models\\PayrollEntry')
                    && $this->modelTableReady('Centrex\\Payroll\\Models\\Employee'),
            ],
        ];
    }

    private function salesOrders(DateTimeInterface $historyStart, DateTimeInterface $historyEnd): Collection
    {
        $query = SaleOrder::query()
            ->where('document_type', 'order')
            ->whereIn('status', [
                SaleOrderStatus::CONFIRMED->value,
                SaleOrderStatus::PROCESSING->value,
                SaleOrderStatus::PARTIAL->value,
                SaleOrderStatus::FULFILLED->value,
            ])
            ->whereBetween('ordered_at', [$historyStart, $historyEnd]);

        CommercialTeamAccess::applySalesScope($query);

        return $query->get([
            'id',
            'total_local',
            'total_amount',
            'cogs_amount',
            'created_by',
            'sales_manager_id',
            'sales_assistant_manager_id',
            'sales_executive_id',
        ]);
    }

    private function salesHistory(Collection $orders, int $observedDays): array
    {
        $revenue = (float) $orders->sum(fn (SaleOrder $order): float => (float) $order->total_local ?: (float) $order->total_amount);
        $cogs = (float) $orders->sum('cogs_amount');
        $grossProfit = max(0.0, $revenue - $cogs);

        return [
            'orders_count'     => $orders->count(),
            'revenue'          => round($revenue, 2),
            'cogs'             => round($cogs, 2),
            'gross_profit'     => round($grossProfit, 2),
            'gross_margin_pct' => $revenue > 0 ? round($grossProfit / $revenue * 100, 2) : 0.0,
            'daily_revenue'    => round($revenue / $observedDays, 2),
        ];
    }

    private function targetFigures(
        float $historyRevenue,
        float $historyExpense,
        float $historyPayroll,
        int $observedDays,
        int $targetDays,
        float $expectedGrossMarginPct,
        float $desiredNetMarginPct,
        float $growthPct,
        float $expenseAllocationPct,
    ): array {
        $targetExpense = round((($historyExpense * ($expenseAllocationPct / 100)) / $observedDays) * $targetDays, 2);
        $targetPayroll = round(($historyPayroll / $observedDays) * $targetDays, 2);
        $targetCostBase = round($targetExpense + $targetPayroll, 2);
        $grossMarginRate = $expectedGrossMarginPct / 100;
        $netMarginRate = $desiredNetMarginPct / 100;
        $contributionRate = max(0.01, $grossMarginRate - $netMarginRate);
        $baseTargetRevenue = $targetCostBase > 0 ? $targetCostBase / $contributionRate : 0.0;
        $targetRevenue = round($baseTargetRevenue * (1 + ($growthPct / 100)), 2);
        $dailyTargetRevenue = $targetDays > 0 ? round($targetRevenue / $targetDays, 2) : 0.0;
        $recentDailyRevenue = round($historyRevenue / $observedDays, 2);

        return [
            'expense'                 => $targetExpense,
            'payroll'                 => $targetPayroll,
            'cost_base'               => $targetCostBase,
            'revenue'                 => $targetRevenue,
            'daily_revenue'           => $dailyTargetRevenue,
            'gross_profit'            => round($targetRevenue * $grossMarginRate, 2),
            'net_profit'              => round(($targetRevenue * $grossMarginRate) - $targetCostBase, 2),
            'required_daily_lift_pct' => $recentDailyRevenue > 0
                ? round((($dailyTargetRevenue - $recentDailyRevenue) / $recentDailyRevenue) * 100, 2)
                : null,
            'contribution_rate_pct' => round($contributionRate * 100, 2),
        ];
    }

    private function expenseAllocationPct(?float $manualPct, int $salesTeamCount, int $allSalesTeamCount): float
    {
        return $manualPct === null
            ? $this->autoExpenseAllocationPct($salesTeamCount, $allSalesTeamCount)
            : max(0.0, min(100.0, $manualPct));
    }

    private function autoExpenseAllocationPct(int $salesTeamCount, int $allSalesTeamCount): float
    {
        return $allSalesTeamCount > 0
            ? round($salesTeamCount / $allSalesTeamCount * 100, 2)
            : 100.0;
    }

    private function salesTeamUserIds(?array $visibleUserIds): Collection
    {
        if (!$this->modelTableReady(CommercialTeamMember::class)) {
            return collect();
        }

        $query = CommercialTeamMember::query()
            ->where('workflow', 'sales')
            ->where('is_active', true);

        if ($visibleUserIds !== null) {
            $query->whereIn('user_id', $visibleUserIds);
        }

        return $query->pluck('user_id')
            ->filter()
            ->unique()
            ->values()
            ->map(fn ($id): int => (int) $id);
    }

    private function accountingExpenseTotal(DateTimeInterface $historyStart, DateTimeInterface $historyEnd): float
    {
        $expenseClass = 'Centrex\\Accounting\\Models\\Expense';

        if (!$this->modelTableReady($expenseClass)) {
            return 0.0;
        }

        return (float) $expenseClass::query()
            ->whereBetween('expense_date', [$historyStart->toDateString(), $historyEnd->toDateString()])
            ->whereIn('status', ['approved', 'partial', 'paid', 'settled'])
            ->sum('total');
    }

    private function salesPayrollTotal(Collection $salesTeamUserIds, DateTimeInterface $historyStart, DateTimeInterface $historyEnd, int $observedDays): float
    {
        $employeeClass = 'Centrex\\Payroll\\Models\\Employee';
        $entryClass = 'Centrex\\Payroll\\Models\\PayrollEntry';
        $lineClass = 'Centrex\\Payroll\\Models\\PayrollEntryLine';

        if (
            $salesTeamUserIds->isEmpty()
            || !$this->modelTableReady($employeeClass)
            || !$this->modelTableReady($entryClass)
            || !$this->modelTableReady($lineClass)
        ) {
            return 0.0;
        }

        $userClass = (string) config('auth.providers.users.model', 'App\\Models\\User');
        $employees = $employeeClass::query()
            ->where('modelable_type', $userClass)
            ->whereIn('modelable_id', $salesTeamUserIds->all())
            ->get(['id', 'monthly_salary']);

        if ($employees->isEmpty()) {
            return 0.0;
        }

        $payrollLines = (float) $lineClass::query()
            ->whereIn('employee_id', $employees->pluck('id')->all())
            ->whereHas('payrollEntry', function ($query) use ($historyStart, $historyEnd): void {
                $query->whereBetween('date', [$historyStart->toDateString(), $historyEnd->toDateString()])
                    ->where('status', 'approved');
            })
            ->sum('amount');

        if ($payrollLines > 0) {
            return $payrollLines;
        }

        return round(((float) $employees->sum('monthly_salary') / 30) * $observedDays, 2);
    }

    private function modelTableReady(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }

        $model = new $class();

        if (!$model instanceof Model) {
            return false;
        }

        return Schema::connection($model->getConnectionName())->hasTable($model->getTable());
    }
}

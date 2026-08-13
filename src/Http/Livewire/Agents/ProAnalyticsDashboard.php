<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Agents;

use Centrex\Inventory\Enums\{OrderRole, SaleOrderStatus};
use Centrex\Inventory\Models\{Agent, Customer, SaleOrder};
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

class ProAnalyticsDashboard extends Component
{
    #[Url(as: 'period')]
    public string $period = 'this_month';

    public function render(): \Illuminate\View\View
    {
        return view('inventory::livewire.agents.pro-analytics-dashboard', $this->fetchAnalytics());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon} */
    private function dateRange(): array
    {
        return match ($this->period) {
            'last_30'   => [now()->subDays(30)->startOfDay(), now()->endOfDay()],
            'last_90'   => [now()->subDays(90)->startOfDay(), now()->endOfDay()],
            'this_year' => [now()->startOfYear(), now()->endOfDay()],
            default     => [now()->startOfMonth(), now()->endOfDay()],
        };
    }

    private function periodLabel(): string
    {
        return match ($this->period) {
            'last_30'   => 'Last 30 Days',
            'last_90'   => 'Last 90 Days',
            'this_year' => 'This Year (' . now()->year . ')',
            default     => 'This Month (' . now()->format('M Y') . ')',
        };
    }

    // ── Main data fetch ───────────────────────────────────────────────────────

    private function fetchAnalytics(): array
    {
        [$from, $to] = $this->dateRange();

        $soTable = (new SaleOrder)->getTable();
        $custTable = (new Customer)->getTable();
        $excluded = [SaleOrderStatus::CANCELLED->value, SaleOrderStatus::RETURNED->value];

        return [
            'summary'       => $this->summary($soTable, $from, $to, $excluded),
            'agentSummary'  => $this->agentSummary($soTable, $from, $to, $excluded),
            'agentRows'     => $this->agentRows($soTable, $custTable, $from, $to, $excluded),
            'revenueByRole' => $this->revenueByRole($soTable, $from, $to, $excluded),
            'revenueByTier' => $this->revenueByTier($soTable, $from, $to, $excluded),
            'monthlyTrend'  => $this->monthlyTrend($soTable, $excluded),
            'periodLabel'   => $this->periodLabel(),
        ];
    }

    private function summary(string $soTable, mixed $from, mixed $to, array $excluded): object
    {
        return DB::table($soTable)
            ->whereBetween('ordered_at', [$from, $to])
            ->whereNotIn('status', $excluded)
            ->where('order_role', '!=', OrderRole::AGENT_B2B->value)
            ->selectRaw('
                COUNT(*) as total_orders,
                COALESCE(SUM(total_amount), 0) as total_revenue,
                COALESCE(SUM(cogs_amount),  0) as total_cogs
            ')
            ->first();
    }

    private function agentSummary(string $soTable, mixed $from, mixed $to, array $excluded): object
    {
        return DB::table("{$soTable} as b2c")
            ->join("{$soTable} as b2b", 'b2b.id', '=', 'b2c.paired_sale_order_id')
            ->where('b2c.order_role', OrderRole::AGENT_B2C->value)
            ->whereNotIn('b2c.status', $excluded)
            ->whereBetween('b2c.ordered_at', [$from, $to])
            ->selectRaw('
                COUNT(*) as agent_orders,
                COALESCE(SUM(b2c.total_amount), 0)                    as b2c_revenue,
                COALESCE(SUM(b2b.total_amount), 0)                    as b2b_cost,
                COALESCE(SUM(b2c.total_amount - b2b.total_amount), 0) as agent_margin
            ')
            ->first();
    }

    private function agentRows(string $soTable, string $custTable, mixed $from, mixed $to, array $excluded): \Illuminate\Support\Collection
    {
        $agentTable = (new Agent)->getTable();

        return DB::table("{$soTable} as b2c")
            ->join("{$soTable} as b2b", 'b2b.id', '=', 'b2c.paired_sale_order_id')
            ->join("{$custTable} as c", 'c.id', '=', 'b2c.agent_customer_id')
            ->leftJoin("{$agentTable} as ag", 'ag.customer_id', '=', 'b2c.agent_customer_id')
            ->where('b2c.order_role', OrderRole::AGENT_B2C->value)
            ->whereNotIn('b2c.status', $excluded)
            ->whereBetween('b2c.ordered_at', [$from, $to])
            ->groupBy('b2c.agent_customer_id', 'ag.id', 'c.name', 'c.code')
            ->selectRaw('
                b2c.agent_customer_id,
                ag.id   as agent_id,
                c.name  as agent_name,
                c.code  as agent_code,
                COUNT(*) as orders_count,
                COALESCE(SUM(b2c.total_amount), 0)                    as b2c_revenue,
                COALESCE(SUM(b2b.total_amount), 0)                    as b2b_cost,
                COALESCE(SUM(b2c.total_amount - b2b.total_amount), 0) as margin
            ')
            ->orderByRaw('SUM(b2c.total_amount - b2b.total_amount) DESC')
            ->limit(15)
            ->get()
            ->map(function (object $row): object {
                $row->margin_pct = (float) $row->b2c_revenue > 0
                    ? round((float) $row->margin / (float) $row->b2c_revenue * 100, 1)
                    : 0.0;

                return $row;
            });
    }

    private function revenueByRole(string $soTable, mixed $from, mixed $to, array $excluded): \Illuminate\Support\Collection
    {
        return DB::table($soTable)
            ->whereBetween('ordered_at', [$from, $to])
            ->whereNotIn('status', $excluded)
            ->where('order_role', '!=', OrderRole::AGENT_B2B->value)
            ->groupBy('order_role')
            ->selectRaw('order_role, COUNT(*) as orders_count, COALESCE(SUM(total_amount), 0) as revenue')
            ->orderByRaw('SUM(total_amount) DESC')
            ->get();
    }

    private function revenueByTier(string $soTable, mixed $from, mixed $to, array $excluded): \Illuminate\Support\Collection
    {
        return DB::table($soTable)
            ->whereBetween('ordered_at', [$from, $to])
            ->whereNotIn('status', $excluded)
            ->where('order_role', '!=', OrderRole::AGENT_B2B->value)
            ->whereNotNull('price_tier_code')
            ->groupBy('price_tier_code')
            ->selectRaw('price_tier_code, COUNT(*) as orders_count, COALESCE(SUM(total_amount), 0) as revenue')
            ->orderByRaw('SUM(total_amount) DESC')
            ->get();
    }

    /**
     * Grouped in PHP rather than SQL (e.g. MySQL's DATE_FORMAT) so this works the same
     * across every DB driver this package supports, including the SQLite test suite —
     * matches AccountingDashboard's precedent for this exact kind of MySQL-only pitfall.
     */
    private function monthlyTrend(string $soTable, array $excluded): array
    {
        $months = collect(range(5, 0))
            ->map(fn (int $i) => now()->subMonths($i)->startOfMonth());

        $rows = DB::table($soTable)
            ->whereBetween('ordered_at', [$months->first(), now()->endOfDay()])
            ->whereNotIn('status', $excluded)
            ->where('order_role', '!=', OrderRole::AGENT_B2B->value)
            ->select(['ordered_at', 'order_role', 'total_amount'])
            ->get()
            ->groupBy(fn ($row) => \Illuminate\Support\Carbon::parse($row->ordered_at)->format('Y-m'));

        $categories = $months->map(fn ($m) => $m->format('M Y'))->toArray();
        $totalRevenue = [];
        $agentRevenue = [];
        $orderCounts = [];

        foreach ($months as $month) {
            $key = $month->format('Y-m');
            $monthRows = $rows->get($key, collect());
            $totalRevenue[] = round((float) $monthRows->sum('total_amount'), 2);
            $agentRevenue[] = round(
                (float) $monthRows->where('order_role', OrderRole::AGENT_B2C->value)->sum('total_amount'),
                2,
            );
            $orderCounts[] = $monthRows->count();
        }

        return [
            'categories' => $categories,
            'series'     => [
                ['name' => 'Total Revenue', 'data' => $totalRevenue],
                ['name' => 'Agent Revenue', 'data' => $agentRevenue],
            ],
            'order_counts' => $orderCounts,
        ];
    }
}

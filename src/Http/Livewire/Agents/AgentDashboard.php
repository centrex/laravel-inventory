<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Agents;

use Centrex\Inventory\Enums\{OrderRole, SaleOrderStatus};
use Centrex\Inventory\Models\{Agent, SaleOrder};
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Agent Dashboard')]
class AgentDashboard extends Component
{
    public int $agentId;

    public function mount(int $agentId): void
    {
        $this->agentId = $agentId;
        Agent::findOrFail($agentId);
    }

    public function render(): View
    {
        $agent = Agent::with('customer')->withCount('customers')->findOrFail($this->agentId);

        $excluded = [SaleOrderStatus::CANCELLED->value, SaleOrderStatus::RETURNED->value];
        $orderStats = null;
        $recentOrders = collect();

        if ($agent->customer_id) {
            $soTable = (new SaleOrder())->getTable();

            $orderStats = DB::table("{$soTable} as b2c")
                ->leftJoin("{$soTable} as b2b", 'b2b.id', '=', 'b2c.paired_sale_order_id')
                ->where('b2c.order_role', OrderRole::AGENT_B2C->value)
                ->where('b2c.agent_customer_id', $agent->customer_id)
                ->whereNotIn('b2c.status', $excluded)
                ->selectRaw('
                    COUNT(*) as orders_count,
                    COALESCE(SUM(b2c.total_amount), 0) as b2c_revenue,
                    COALESCE(SUM(b2b.total_amount), 0) as b2b_cost,
                    COALESCE(SUM(b2c.total_amount - COALESCE(b2b.total_amount, 0)), 0) as margin
                ')
                ->first();

            $recentOrders = SaleOrder::with('customer')
                ->where('order_role', OrderRole::AGENT_B2C->value)
                ->where('agent_customer_id', $agent->customer_id)
                ->orderByDesc('ordered_at')
                ->limit(10)
                ->get();
        }

        $marginPct = $orderStats && (float) $orderStats->b2c_revenue > 0
            ? round((float) $orderStats->margin / (float) $orderStats->b2c_revenue * 100, 1)
            : 0.0;

        return view('inventory::livewire.agents.agent-dashboard', [
            'agent'        => $agent,
            'orderStats'   => $orderStats,
            'recentOrders' => $recentOrders,
            'marginPct'    => $marginPct,
        ]);
    }
}

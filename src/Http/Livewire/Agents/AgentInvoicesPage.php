<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Agents;

use Centrex\Inventory\Models\Agent;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Agent Invoices')]
class AgentInvoicesPage extends Component
{
    public int $agentId;

    public string $search = '';

    public string $statusFilter = '';

    public function mount(int $agentId): void
    {
        $this->agentId = $agentId;
        Agent::findOrFail($agentId);
    }

    public function updatedSearch(): void {}

    public function render(): View
    {
        $agent = Agent::with('customer')->findOrFail($this->agentId);

        $invoices = collect();
        $hasAccounting = class_exists(\Centrex\Accounting\Models\Invoice::class);

        if ($hasAccounting) {
            $search = $this->search;
            $status = $this->statusFilter;

            $invoices = \Centrex\Accounting\Models\Invoice::query()
                ->with('customer')
                ->where('source_type', 'agent_b2b')
                ->where('source_id', $this->agentId)
                ->when($search !== '', fn ($q) => $q->where(fn ($q2) => $q2
                    ->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('source_reference', 'like', "%{$search}%")))
                ->when($status !== '', fn ($q) => $q->where('status', $status))
                ->latest('invoice_date')
                ->get();
        }

        $totals = [
            'count'       => $invoices->count(),
            'total'       => $invoices->sum(fn ($inv) => (float) $inv->total),
            'paid'        => $invoices->sum(fn ($inv) => (float) $inv->paid_amount),
            'outstanding' => $invoices->sum(fn ($inv) => (float) $inv->total - (float) $inv->paid_amount),
        ];

        return view('inventory::livewire.agents.agent-invoices', [
            'agent'         => $agent,
            'invoices'      => $invoices,
            'totals'        => $totals,
            'hasAccounting' => $hasAccounting,
        ]);
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Agents;

use Centrex\Inventory\Models\Agent;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Agent Customers')]
class AgentCustomersPage extends Component
{
    public int $agentId;

    public string $search = '';

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    public function mount(int $agentId): void
    {
        $this->agentId = $agentId;
        Agent::findOrFail($agentId);
    }

    public function updatedSearch(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;
    }

    public function detach(int $customerId): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;

        try {
            Agent::findOrFail($this->agentId)->customers()->detach($customerId);
            $this->successMessage = 'Customer removed from agent.';
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function render(): View
    {
        $agent = Agent::with('customer')->findOrFail($this->agentId);

        $query = $agent->customers()
            ->withPivot(['territory', 'assigned_at', 'is_primary'])
            ->orderByPivot('is_primary', 'desc')
            ->orderBy('name');

        if ($this->search !== '') {
            $search = $this->search;
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%"));
        }

        return view('inventory::livewire.agents.agent-customers', [
            'agent'     => $agent,
            'customers' => $query->get(),
        ]);
    }
}

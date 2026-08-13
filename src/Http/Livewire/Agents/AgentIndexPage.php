<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Agents;

use Centrex\Inventory\Models\Agent;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\{Component, WithPagination};

#[Title('Sales Agents')]
class AgentIndexPage extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $confirmDeleteId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function confirmDelete(int $id): void
    {
        $this->confirmDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmDeleteId = null;
    }

    public function delete(): void
    {
        if (!$this->confirmDeleteId) {
            return;
        }

        Agent::findOrFail($this->confirmDeleteId)->delete();
        $this->confirmDeleteId = null;
    }

    public function render(): View
    {
        $agents = Agent::query()
            ->when($this->search, function ($q): void {
                $q->where(fn ($q2) => $q2
                    ->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('code', 'like', '%' . $this->search . '%')
                    ->orWhere('email', 'like', '%' . $this->search . '%')
                    ->orWhere('zone', 'like', '%' . $this->search . '%'));
            })
            ->withCount('customers')
            ->orderBy('name')
            ->paginate(20);

        return view('inventory::livewire.agents.agent-index', [
            'agents' => $agents,
        ]);
    }
}

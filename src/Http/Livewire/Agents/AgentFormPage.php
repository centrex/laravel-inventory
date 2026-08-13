<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Agents;

use Centrex\Inventory\Enums\PriceTierCode;
use Centrex\Inventory\Models\{Agent, Customer};
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Agent')]
class AgentFormPage extends Component
{
    // ── Route parameter ────────────────────────────────────────────────────────

    public ?int $agentId = null;

    // ── Agent fields ───────────────────────────────────────────────────────────

    public string $code = '';

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $zone = '';

    public string $area = '';

    public string $priceTierCode = 'b2b_wholesale';

    public string $commissionRatePct = '0';

    public ?int $billingCustomerId = null;

    public bool $isActive = true;

    public string $notes = '';

    // ── Customer attachment ────────────────────────────────────────────────────

    public ?int $attachCustomerId = null;

    public string $attachTerritory = '';

    public bool $attachIsPrimary = false;

    // ── Flash ──────────────────────────────────────────────────────────────────

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    // ── Lifecycle ──────────────────────────────────────────────────────────────

    public function mount(?int $agentId = null): void
    {
        $this->agentId = $agentId;

        if (!$agentId) {
            return;
        }

        $agent = Agent::findOrFail($agentId);

        $this->code = (string) ($agent->code ?? '');
        $this->name = (string) ($agent->name ?? '');
        $this->email = (string) ($agent->email ?? '');
        $this->phone = (string) ($agent->phone ?? '');
        $this->zone = (string) ($agent->zone ?? '');
        $this->area = (string) ($agent->area ?? '');
        $this->priceTierCode = (string) ($agent->price_tier_code ?? 'b2b_wholesale');
        $this->commissionRatePct = (string) ($agent->commission_rate_pct ?? '0');
        $this->billingCustomerId = $agent->customer_id;
        $this->isActive = (bool) $agent->is_active;
        $this->notes = (string) ($agent->notes ?? '');
    }

    // ── Save agent ─────────────────────────────────────────────────────────────

    public function save(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;

        $this->validate([
            'code'              => 'nullable|string|max:50',
            'name'              => 'required|string|max:255',
            'email'             => 'nullable|email|max:255',
            'phone'             => 'nullable|string|max:50',
            'zone'              => 'nullable|string|max:100',
            'area'              => 'nullable|string|max:100',
            'priceTierCode'     => 'required|string',
            'commissionRatePct' => 'required|numeric|min:0|max:100',
            'billingCustomerId' => 'nullable|integer|min:1',
        ]);

        try {
            $data = [
                'code'                => $this->code ?: null,
                'name'                => $this->name,
                'email'               => $this->email ?: null,
                'phone'               => $this->phone ?: null,
                'zone'                => $this->zone ?: null,
                'area'                => $this->area ?: null,
                'price_tier_code'     => $this->priceTierCode,
                'commission_rate_pct' => (float) $this->commissionRatePct,
                'customer_id'         => $this->billingCustomerId,
                'is_active'           => $this->isActive,
                'notes'               => $this->notes ?: null,
            ];

            if ($this->agentId) {
                Agent::findOrFail($this->agentId)->update($data);
                $this->successMessage = "Agent \"{$this->name}\" updated.";
            } else {
                $agent = Agent::create($data);
                $this->agentId = $agent->id;
                $this->successMessage = "Agent \"{$agent->name}\" created. You can now link customers below.";
            }
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    // ── Customer attachment ────────────────────────────────────────────────────

    public function attachCustomer(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;

        $this->validate([
            'attachCustomerId' => 'required|integer|min:1',
            'attachTerritory'  => 'nullable|string|max:100',
        ]);

        try {
            $agent = Agent::findOrFail($this->agentId);

            if ($agent->customers()->where('customer_id', $this->attachCustomerId)->exists()) {
                $this->errorMessage = 'This customer is already linked to this agent.';

                return;
            }

            $agent->customers()->attach($this->attachCustomerId, [
                'territory'   => $this->attachTerritory ?: null,
                'assigned_at' => now()->toDateString(),
                'is_primary'  => $this->attachIsPrimary,
            ]);

            $this->attachCustomerId = null;
            $this->attachTerritory = '';
            $this->attachIsPrimary = false;
            $this->successMessage = 'Customer linked successfully.';
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function detachCustomer(int $customerId): void
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

    // ── Render ─────────────────────────────────────────────────────────────────

    public function render(): View
    {
        $linkedCustomers = $this->agentId
            ? Agent::find($this->agentId)
                ?->customers()
                ->withPivot(['territory', 'assigned_at', 'is_primary'])
                ->orderBy('name')
                ->get()
                ?? collect()
            : collect();

        $linkedIds = $linkedCustomers->pluck('id')->all();

        $allCustomers = Customer::query()
            ->where('is_active', 1)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $availableCustomers = $allCustomers->whereNotIn('id', $linkedIds)->values();

        return view('inventory::livewire.agents.agent-form', [
            'linkedCustomers'    => $linkedCustomers,
            'availableCustomers' => $availableCustomers,
            'allCustomers'       => $allCustomers,
            'tiers'              => PriceTierCode::ordered(),
            'isEdit'             => (bool) $this->agentId,
        ]);
    }
}

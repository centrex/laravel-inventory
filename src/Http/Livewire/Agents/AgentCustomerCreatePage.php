<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Agents;

use Centrex\Inventory\Enums\PriceTierCode;
use Centrex\Inventory\Models\{Agent, Customer};
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('New Customer for Agent')]
class AgentCustomerCreatePage extends Component
{
    // ── Route parameter ────────────────────────────────────────────────────────

    public int $agentId;

    // ── Customer fields ────────────────────────────────────────────────────────

    public string $name = '';

    public string $code = '';

    public string $organizationName = '';

    public string $email = '';

    public string $phone = '';

    public string $zone = '';

    public string $area = '';

    public string $currency = 'BDT';

    public string $creditLimitAmount = '10000';

    public string $priceTierCode = 'b2c_retail';

    public bool $isActive = true;

    // ── Pivot fields ───────────────────────────────────────────────────────────

    public string $territory = '';

    public bool $isPrimary = false;

    // ── Flash ──────────────────────────────────────────────────────────────────

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    // ── Lifecycle ──────────────────────────────────────────────────────────────

    public function mount(int $agentId): void
    {
        $this->agentId = $agentId;
        Agent::findOrFail($agentId); // 404 guard
    }

    // ── Save ───────────────────────────────────────────────────────────────────

    public function save(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;

        $this->validate([
            'name'              => 'required|string|max:255',
            'code'              => 'required|string|max:30|unique:' . (new Customer())->getTable() . ',code',
            'organizationName'  => 'nullable|string|max:255',
            'email'             => 'nullable|email|max:255',
            'phone'             => 'nullable|string|max:50',
            'zone'              => 'nullable|string|max:100',
            'area'              => 'nullable|string|max:100',
            'currency'          => 'required|string|max:10',
            'creditLimitAmount' => 'required|numeric|min:0',
            'priceTierCode'     => 'required|string',
            'territory'         => 'nullable|string|max:100',
        ]);

        try {
            $savedName = $this->name;

            DB::transaction(function () use ($savedName): void {
                $customer = Customer::create([
                    'name'                => $savedName,
                    'code'                => $this->code,
                    'organization_name'   => $this->organizationName ?: null,
                    'email'               => $this->email ?: null,
                    'phone'               => $this->phone ?: null,
                    'zone'                => $this->zone ?: null,
                    'area'                => $this->area ?: null,
                    'currency'            => $this->currency,
                    'credit_limit_amount' => (float) $this->creditLimitAmount,
                    'price_tier_code'     => $this->priceTierCode,
                    'is_active'           => $this->isActive,
                    'agent_id'            => $this->agentId,
                ]);

                Agent::findOrFail($this->agentId)->customers()->attach($customer->id, [
                    'territory'   => $this->territory ?: null,
                    'assigned_at' => now()->toDateString(),
                    'is_primary'  => $this->isPrimary,
                ]);
            });

            $this->successMessage = "Customer \"{$savedName}\" created and linked to this agent.";
            $this->reset(['name', 'code', 'organizationName', 'email', 'phone', 'zone', 'area',
                'creditLimitAmount', 'territory', 'isPrimary']);
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    // ── Render ─────────────────────────────────────────────────────────────────

    public function render(): View
    {
        return view('inventory::livewire.agents.agent-customer-create', [
            'agent' => Agent::with('customer')->findOrFail($this->agentId),
            'tiers' => PriceTierCode::ordered(),
        ]);
    }
}

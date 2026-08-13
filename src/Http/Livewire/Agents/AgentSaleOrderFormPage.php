<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Agents;

use Centrex\Inventory\Exceptions\PriceNotFoundException;
use Centrex\Inventory\Facades\Inventory;
use Centrex\Inventory\Models\{Agent, Customer, Product, Warehouse};
use Centrex\Inventory\Services\AgentOrderService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Title('Create Agent Sale Order')]
class AgentSaleOrderFormPage extends Component
{
    // ── Form state ─────────────────────────────────────────────────────────────

    public ?int $warehouseId = null;

    public ?int $customerId = null;    // end B2C customer

    public ?int $agentId = null;    // inv_agents.id of the sales agent

    public string $currency = 'BDT';

    public string $notes = '';

    /** @var array<int, array{product_id: int|null, variant_id: int|null, qty: float, b2c_price: float, b2b_price: float}> */
    public array $items = [];

    // ── Computed totals ────────────────────────────────────────────────────────

    public float $b2cTotal = 0.0;

    public float $b2bTotal = 0.0;

    public float $agentMargin = 0.0;

    // ── Flash messages ─────────────────────────────────────────────────────────

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    // ── Lifecycle ──────────────────────────────────────────────────────────────

    public function mount(): void
    {
        $this->addItem();
    }

    // ── Item management ────────────────────────────────────────────────────────

    public function addItem(): void
    {
        $this->items[] = [
            'product_id' => null,
            'variant_id' => null,
            'qty'        => 1,
            'b2c_price'  => 0.0,
            'b2b_price'  => 0.0,
        ];
    }

    public function removeItem(int $index): void
    {
        array_splice($this->items, $index, 1);
        $this->recalculate();
    }

    /**
     * Triggered by Livewire whenever any items.*.product_id or items.*.qty changes.
     */
    public function updatedItems(mixed $_value, string $key): void
    {
        [$index, $field] = array_pad(explode('.', $key, 2), 2, '');

        if (in_array($field, ['product_id', 'variant_id'], true)) {
            $this->loadPricesForItem((int) $index);
        }

        $this->recalculate();
    }

    public function updatedWarehouseId(): void
    {
        foreach (array_keys($this->items) as $index) {
            $this->loadPricesForItem($index);
        }
        $this->recalculate();
    }

    public function updatedAgentId(): void
    {
        // Reset customer selection when agent changes — the customer list will re-filter
        $this->customerId = null;
    }

    // ── Price loading ──────────────────────────────────────────────────────────

    public function loadPricesForItem(int $index): void
    {
        $item = $this->items[$index] ?? null;

        if (!$item || !$item['product_id']) {
            return;
        }

        $productId = (int) $item['product_id'];
        $variantId = $item['variant_id'] ? (int) $item['variant_id'] : null;
        $wh = $this->warehouseId ? (int) $this->warehouseId : $this->defaultWarehouseId();

        $b2cTier = config('inventory.agent.default_b2c_tier', 'b2c_retail');
        $b2bTier = config('inventory.agent.default_b2b_tier', 'b2b_wholesale');

        try {
            $this->items[$index]['b2c_price'] = (float) Inventory::resolvePrice($productId, $b2cTier, $wh, variantId: $variantId)->price_amount;
        } catch (PriceNotFoundException) {
            $this->items[$index]['b2c_price'] = 0.0;
        }

        try {
            $this->items[$index]['b2b_price'] = (float) Inventory::resolvePrice($productId, $b2bTier, $wh, variantId: $variantId)->price_amount;
        } catch (PriceNotFoundException) {
            $this->items[$index]['b2b_price'] = 0.0;
        }
    }

    public function recalculate(): void
    {
        $b2c = 0.0;
        $b2b = 0.0;

        foreach ($this->items as $item) {
            $qty = max(0.0, (float) ($item['qty'] ?? 0));
            $b2c += $qty * (float) ($item['b2c_price'] ?? 0);
            $b2b += $qty * (float) ($item['b2b_price'] ?? 0);
        }

        $this->b2cTotal = round($b2c, 2);
        $this->b2bTotal = round($b2b, 2);
        $this->agentMargin = round($b2c - $b2b, 2);
    }

    // ── Submit ─────────────────────────────────────────────────────────────────

    public function submit(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;

        $this->validate([
            'warehouseId'        => 'required|integer|min:1',
            'customerId'         => 'required|integer|min:1',
            'agentId'            => 'required|integer|min:1',
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|min:1',
            'items.*.qty'        => 'required|numeric|min:0.0001',
        ]);

        try {
            $result = app(AgentOrderService::class)->createPairedOrders(
                b2cData: [
                    'warehouse_id' => $this->warehouseId,
                    'customer_id'  => $this->customerId,
                    'currency'     => $this->currency,
                    'notes'        => $this->notes ?: null,
                    'ordered_at'   => now(),
                    'items'        => collect($this->items)
                        ->filter(fn ($i) => $i['product_id'])
                        ->map(fn ($i) => [
                            'product_id'       => (int) $i['product_id'],
                            'variant_id'       => $i['variant_id'] ? (int) $i['variant_id'] : null,
                            'qty_ordered'      => (float) $i['qty'],
                            'unit_price_local' => (float) $i['b2c_price'],
                        ])->values()->toArray(),
                ],
                agentId: $this->agentId,
            );

            $b2c = $result['b2c_order'];
            $b2b = $result['b2b_order'];

            $this->successMessage = "Orders created — Customer: {$b2c->so_number} (B2C) / Agent: {$b2b->so_number} (B2B)";
            $this->reset(['customerId', 'notes', 'b2cTotal', 'b2bTotal', 'agentMargin']);
            $this->items = [];
            $this->addItem();

            $this->dispatch('agent-order-created', b2cOrderId: $b2c->id, b2bOrderId: $b2b->id);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    // ── Render ─────────────────────────────────────────────────────────────────

    public function render(): View
    {
        $products = Product::query()
            ->where('is_active', 1)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'barcode']);

        $productsJson = $products
            ->map(fn (Product $p): array => [
                'id'      => (int) $p->id,
                'name'    => (string) $p->name,
                'sku'     => (string) ($p->sku ?? ''),
                'barcode' => (string) ($p->barcode ?? ''),
            ])
            ->values()
            ->all();

        $customers = $this->agentId
            ? Agent::find($this->agentId)?->customers()->where('is_active', 1)->orderBy('name')->get() ?? collect()
            : Customer::query()->where('is_active', 1)->orderBy('name')->get();

        return view('inventory::livewire.agents.agent-sale-order-form', [
            'warehouses'   => Warehouse::query()->where('is_active', 1)->orderBy('name')->get(),
            'customers'    => $customers,
            'agents'       => Agent::query()->where('is_active', 1)->orderBy('name')->get(),
            'products'     => $products,
            'productsJson' => $productsJson,
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function defaultWarehouseId(): int
    {
        return (int) (Warehouse::query()->where('is_default', 1)->value('id') ?? 1);
    }
}

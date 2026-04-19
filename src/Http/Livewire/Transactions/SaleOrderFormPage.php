<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Enums\PriceTierCode;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Enums\SaleOrderStatus;
use Centrex\Inventory\Models\{Customer, PriceTier, Product, SaleOrder, Warehouse};
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class SaleOrderFormPage extends Component
{
    public ?int $recordId = null;

    public ?int $warehouse_id = null;

    public ?int $customer_id = null;

    public string $price_tier_code = 'retail';

    public string $currency = 'BDT';

    public ?float $exchange_rate = null;

    public float $tax_local = 0;

    public float $discount_local = 0;

    public string $notes = '';

    public bool $credit_override = false;

    public string $credit_override_notes = '';

    public array $items = [];

    public function mount(?int $recordId = null): void
    {
        $this->price_tier_code = PriceTierCode::RETAIL->value;
        $this->items = [$this->blankItem()];

        if ($recordId !== null) {
            $this->loadOrder($recordId);
        }
    }

    public function addItem(): void
    {
        $this->items[] = $this->blankItem();
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function save(): \Illuminate\Http\RedirectResponse
    {
        $validated = $this->validate($this->rules());

        if ($this->recordId) {
            $saleOrder = $this->updateOrder($validated);
            session()->flash('inventory.status', "Sale order {$saleOrder->so_number} updated.");

            return redirect()->route('inventory.sale-orders.edit', ['recordId' => $saleOrder->getKey()]);
        }

        $saleOrder = app(Inventory::class)->createSaleOrder($validated);
        session()->flash('inventory.status', "Sale order {$saleOrder->so_number} created.");

        return redirect()->route('inventory.sale-orders.edit', ['recordId' => $saleOrder->getKey()]);
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.sale-order-form', [
            'warehouses'             => Warehouse::query()->orderBy('name')->get(),
            'customers'              => Customer::query()->orderBy('name')->get(),
            'products'               => Product::query()->orderBy('name')->get(),
            'priceTiers'             => PriceTier::query()->orderBy('sort_order')->get(),
            'selectedCustomer'       => $this->customer_id ? Customer::query()->find($this->customer_id) : null,
            'customerCreditSnapshot' => $this->customer_id ? app(Inventory::class)->customerCreditSnapshot($this->customer_id) : null,
            'isEditing'              => $this->recordId !== null,
            'editable'               => $this->canEdit(),
            'record'                 => $this->recordId ? SaleOrder::query()->with(['customer', 'warehouse'])->find($this->recordId) : null,
        ]);
    }

    private function rules(): array
    {
        return [
            'warehouse_id'             => ['required', 'integer'],
            'customer_id'              => ['nullable', 'integer'],
            'price_tier_code'          => ['required', 'string'],
            'currency'                 => ['required', 'string', 'size:3'],
            'exchange_rate'            => ['nullable', 'numeric', 'gt:0'],
            'tax_local'                => ['nullable', 'numeric'],
            'discount_local'           => ['nullable', 'numeric'],
            'notes'                    => ['nullable', 'string'],
            'credit_override'          => ['nullable', 'boolean'],
            'credit_override_notes'    => ['nullable', 'string'],
            'items'                    => ['required', 'array', 'min:1'],
            'items.*.product_id'       => ['required', 'integer'],
            'items.*.qty_ordered'      => ['required', 'numeric', 'gt:0'],
            'items.*.price_tier_code'  => ['nullable', 'string'],
            'items.*.unit_price_local' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_pct'     => ['nullable', 'numeric', 'min:0'],
            'items.*.notes'            => ['nullable', 'string'],
        ];
    }

    private function blankItem(): array
    {
        return [
            'product_id'       => null,
            'qty_ordered'      => 1,
            'price_tier_code'  => null,
            'unit_price_local' => null,
            'discount_pct'     => 0,
            'notes'            => '',
        ];
    }

    private function loadOrder(int $recordId): void
    {
        $order = SaleOrder::query()->with('items')->findOrFail($recordId);

        $this->recordId = $order->getKey();
        $this->warehouse_id = $order->warehouse_id;
        $this->customer_id = $order->customer_id;
        $this->price_tier_code = $order->priceTier?->code ?? $this->price_tier_code;
        $this->currency = $order->currency;
        $this->exchange_rate = $order->exchange_rate !== null ? (float) $order->exchange_rate : null;
        $this->tax_local = (float) $order->tax_local;
        $this->discount_local = (float) $order->discount_local;
        $this->notes = (string) ($order->notes ?? '');
        $this->credit_override = (bool) $order->credit_override_required;
        $this->credit_override_notes = (string) ($order->credit_override_notes ?? '');
        $this->items = $order->items->map(fn ($item): array => [
            'product_id' => $item->product_id,
            'qty_ordered' => (float) $item->qty_ordered,
            'price_tier_code' => $item->priceTier?->code,
            'unit_price_local' => (float) $item->unit_price_local,
            'discount_pct' => (float) $item->discount_pct,
            'notes' => (string) ($item->notes ?? ''),
        ])->all();
    }

    private function canEdit(): bool
    {
        if (! $this->recordId) {
            return true;
        }

        $order = SaleOrder::query()->find($this->recordId);

        return in_array($order?->status?->value, [SaleOrderStatus::DRAFT->value, SaleOrderStatus::CONFIRMED->value], true);
    }

    private function updateOrder(array $validated): SaleOrder
    {
        $saleOrder = SaleOrder::query()->with('items')->findOrFail($this->recordId);

        abort_unless($this->canEdit(), 403, 'This sale order can no longer be edited.');

        $products = Product::query()
            ->whereIn('id', collect($validated['items'])->pluck('product_id')->all())
            ->get()
            ->keyBy('id');

        $priceTierMap = PriceTier::query()->pluck('id', 'code');
        $subtotal = 0.0;
        $cogs = 0.0;
        $itemsPayload = [];

        foreach ($validated['items'] as $item) {
            $product = $products->get((int) $item['product_id']);
            $qty = round((float) $item['qty_ordered'], 4);
            $unitPrice = round((float) ($item['unit_price_local'] ?? 0), 4);
            $discountPct = round((float) ($item['discount_pct'] ?? 0), 2);
            $lineBase = $qty * $unitPrice;
            $lineTotal = round($lineBase - ($lineBase * $discountPct / 100), 4);
            $subtotal += $lineTotal;

            $itemsPayload[] = [
                'product_id' => (int) $item['product_id'],
                'price_tier_id' => $item['price_tier_code'] ? $priceTierMap[$item['price_tier_code']] ?? null : null,
                'qty_ordered' => $qty,
                'qty_fulfilled' => 0,
                'unit_price_local' => $unitPrice,
                'unit_price_amount' => $unitPrice,
                'unit_cost_amount' => (float) ($product?->meta['unit_cost_amount'] ?? 0),
                'discount_pct' => $discountPct,
                'line_total_local' => $lineTotal,
                'line_total_amount' => $lineTotal,
                'notes' => $item['notes'] ?? '',
            ];
        }

        $total = round($subtotal + (float) ($validated['tax_local'] ?? 0) - (float) ($validated['discount_local'] ?? 0), 4);

        DB::transaction(function () use ($saleOrder, $validated, $subtotal, $total, $cogs, $itemsPayload): void {
            $saleOrder->fill([
                'warehouse_id' => $validated['warehouse_id'],
                'customer_id' => $validated['customer_id'] ?? null,
                'price_tier_id' => PriceTier::query()->where('code', $validated['price_tier_code'])->value('id'),
                'currency' => $validated['currency'],
                'exchange_rate' => $validated['exchange_rate'] ?? 1,
                'subtotal_local' => round($subtotal, 4),
                'subtotal_amount' => round($subtotal, 4),
                'tax_local' => round((float) ($validated['tax_local'] ?? 0), 4),
                'tax_amount' => round((float) ($validated['tax_local'] ?? 0), 4),
                'discount_local' => round((float) ($validated['discount_local'] ?? 0), 4),
                'discount_amount' => round((float) ($validated['discount_local'] ?? 0), 4),
                'total_local' => $total,
                'total_amount' => $total,
                'cogs_amount' => $cogs,
                'notes' => $validated['notes'] ?? '',
                'credit_override_required' => (bool) ($validated['credit_override'] ?? false),
                'credit_override_notes' => $validated['credit_override_notes'] ?? '',
            ])->save();

            $saleOrder->items()->delete();
            $saleOrder->items()->createMany($itemsPayload);
        });

        return $saleOrder->fresh(['items', 'customer', 'warehouse']);
    }
}

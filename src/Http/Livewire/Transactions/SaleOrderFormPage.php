<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Enums\{PriceTierCode, SaleOrderStatus};
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Customer, Product, SaleOrder, Warehouse, WarehouseProduct};
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class SaleOrderFormPage extends Component
{
    public string $documentType = 'order';

    public ?int $recordId = null;

    public ?int $warehouse_id = null;

    public ?int $customer_id = null;

    public string $price_tier_code = 'b2b_retail';

    public string $currency = 'BDT';

    public ?float $exchange_rate = null;

    public float $tax_local = 0;

    public float $discount_local = 0;

    public string $notes = '';

    public bool $credit_override = false;

    public string $credit_override_notes = '';

    public array $items = [];

    public function mount(int|string|null $recordId = null, string $documentType = 'order'): void
    {
        $recordId = is_numeric($recordId) && (int) $recordId > 0 ? (int) $recordId : null;

        $this->documentType = $documentType === 'quotation' ? 'quotation' : 'order';
        $this->price_tier_code = PriceTierCode::B2B_RETAIL->value;
        $this->items = [$this->blankItem()];

        if ($recordId !== null) {
            $this->loadOrder($recordId);
        } else {
            $first = Warehouse::query()->orderBy('id')->first();

            if ($first) {
                $this->warehouse_id = $first->id;
            }
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

    public function save()
    {
        $validated = $this->validate($this->rules());
        $validated['document_type'] = $this->documentType;
        $this->assertStockAvailability($validated['items']);

        if ($this->recordId) {
            $saleOrder = $this->updateOrder($validated);
            session()->flash('inventory.status', "{$this->documentLabel()} {$saleOrder->so_number} updated.");

            return redirect()->route($this->routeBase() . '.edit', ['recordId' => $saleOrder->getKey()]);
        }

        $saleOrder = app(Inventory::class)->createSaleOrder($validated);
        session()->flash('inventory.status', "{$this->documentLabel()} {$saleOrder->so_number} created.");

        return redirect()->route($this->routeBase() . '.edit', ['recordId' => $saleOrder->getKey()]);
    }

    public function render(): View
    {
        $selectedProductIds = collect($this->items)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->all();
        $availableStock = $this->warehouse_id
            ? WarehouseProduct::query()
                ->with('product')
                ->where('warehouse_id', $this->warehouse_id)
                ->whereRaw('(qty_on_hand - qty_reserved) > 0')
                ->get()
            : collect();
        $products = Product::query()
            ->when(
                $this->warehouse_id,
                fn ($query) => $query->where(function ($builder) use ($availableStock, $selectedProductIds): void {
                    $builder->whereIn('id', $availableStock->pluck('product_id')->all());

                    if ($selectedProductIds !== []) {
                        $builder->orWhereIn('id', $selectedProductIds);
                    }
                }),
            )
            ->orderBy('name')
            ->get();

        return view('inventory::livewire.transactions.sale-order-form', [
            'warehouses'             => Warehouse::query()->orderBy('id')->get(),
            'customers'              => Customer::query()->orderBy('name')->get(),
            'products'               => $products,
            'priceTiers'             => PriceTierCode::options(),
            'selectedCustomer'       => $this->customer_id ? Customer::query()->find($this->customer_id) : null,
            'customerCreditSnapshot' => $this->customer_id ? app(Inventory::class)->customerCreditSnapshot($this->customer_id) : null,
            'isEditing'              => $this->recordId !== null,
            'editable'               => $this->canEdit(),
            'record'                 => $this->recordId ? SaleOrder::query()->with(['customer', 'warehouse'])->find($this->recordId) : null,
            'documentLabel'          => $this->documentLabel(),
            'routeBase'              => $this->routeBase(),
            'availableStock'         => $availableStock->keyBy('product_id'),
        ]);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['warehouse_id', 'price_tier_code'], true)) {
            foreach (array_keys($this->items) as $index) {
                $this->syncItemPrice($index);
            }

            return;
        }

        if (preg_match('/^items\.(\d+)\.(product_id|price_tier_code)$/', $property, $matches)) {
            $this->syncItemPrice((int) $matches[1]);
        }
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
            'price_tier_code'  => PriceTierCode::B2B_RETAIL->value,
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
        $this->price_tier_code = $order->price_tier_code ?: $this->price_tier_code;
        $this->currency = $order->currency;
        $this->exchange_rate = $order->exchange_rate !== null ? (float) $order->exchange_rate : null;
        $this->tax_local = (float) $order->tax_local;
        $this->discount_local = (float) $order->discount_local;
        $this->notes = (string) ($order->notes ?? '');
        $this->credit_override = (bool) $order->credit_override_required;
        $this->credit_override_notes = (string) ($order->credit_override_notes ?? '');
        $this->items = $order->items->map(fn ($item): array => [
            'product_id'       => $item->product_id,
            'qty_ordered'      => (float) $item->qty_ordered,
            'price_tier_code'  => $item->price_tier_code,
            'unit_price_local' => (float) $item->unit_price_local,
            'discount_pct'     => (float) $item->discount_pct,
            'notes'            => (string) ($item->notes ?? ''),
        ])->all();
    }

    private function canEdit(): bool
    {
        if (!$this->recordId) {
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
                'product_id'        => (int) $item['product_id'],
                'price_tier_code'   => $item['price_tier_code'] ?: $validated['price_tier_code'],
                'qty_ordered'       => $qty,
                'qty_fulfilled'     => 0,
                'unit_price_local'  => $unitPrice,
                'unit_price_amount' => $unitPrice,
                'unit_cost_amount'  => (float) ($product?->meta['unit_cost_amount'] ?? 0),
                'discount_pct'      => $discountPct,
                'line_total_local'  => $lineTotal,
                'line_total_amount' => $lineTotal,
                'notes'             => $item['notes'] ?? '',
            ];
        }

        $total = round($subtotal + (float) ($validated['tax_local'] ?? 0) - (float) ($validated['discount_local'] ?? 0), 4);

        DB::transaction(function () use ($saleOrder, $validated, $subtotal, $total, $cogs, $itemsPayload): void {
            $saleOrder->fill([
                'document_type'            => $validated['document_type'] ?? $saleOrder->document_type,
                'warehouse_id'             => $validated['warehouse_id'],
                'customer_id'              => $validated['customer_id'] ?? null,
                'price_tier_code'          => $validated['price_tier_code'],
                'currency'                 => $validated['currency'],
                'exchange_rate'            => $validated['exchange_rate'] ?? 1,
                'subtotal_local'           => round($subtotal, 4),
                'subtotal_amount'          => round($subtotal, 4),
                'tax_local'                => round((float) ($validated['tax_local'] ?? 0), 4),
                'tax_amount'               => round((float) ($validated['tax_local'] ?? 0), 4),
                'discount_local'           => round((float) ($validated['discount_local'] ?? 0), 4),
                'discount_amount'          => round((float) ($validated['discount_local'] ?? 0), 4),
                'total_local'              => $total,
                'total_amount'             => $total,
                'cogs_amount'              => $cogs,
                'notes'                    => $validated['notes'] ?? '',
                'credit_override_required' => (bool) ($validated['credit_override'] ?? false),
                'credit_override_notes'    => $validated['credit_override_notes'] ?? '',
            ])->save();

            $saleOrder->items()->delete();
            $saleOrder->items()->createMany($itemsPayload);
        });

        return $saleOrder->fresh(['items', 'customer', 'warehouse']);
    }

    private function assertStockAvailability(array $items): void
    {
        if (!$this->warehouse_id) {
            return;
        }

        $stockByProduct = WarehouseProduct::query()
            ->where('warehouse_id', $this->warehouse_id)
            ->whereIn('product_id', collect($items)->pluck('product_id')->filter()->all())
            ->get()
            ->keyBy('product_id');

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $available = (float) ($stockByProduct->get($productId)?->qtyAvailable() ?? 0);
            $requested = round((float) $item['qty_ordered'], 4);

            if ($requested > $available + (float) config('inventory.qty_tolerance', 0.0001)) {
                $productName = Product::query()->find($productId)?->name ?? ('#' . $productId);

                throw ValidationException::withMessages([
                    'items' => "Only available stock can be sold. {$productName} has {$available} available.",
                ]);
            }
        }
    }

    private function syncItemPrice(int $index): void
    {
        $productId = (int) ($this->items[$index]['product_id'] ?? 0);

        if (!$productId || !$this->warehouse_id) {
            $this->items[$index]['unit_price_local'] = null;

            return;
        }

        $tierCode = $this->items[$index]['price_tier_code'] ?: $this->price_tier_code ?: PriceTierCode::B2B_RETAIL->value;
        $this->items[$index]['price_tier_code'] = $tierCode;

        try {
            $price = app(Inventory::class)->resolvePrice($productId, $tierCode, (int) $this->warehouse_id);
            $this->items[$index]['unit_price_local'] = (float) ($price->price_local ?: app(Inventory::class)->convertFromBase((float) $price->price_amount, $this->currency ?: 'BDT'));
        } catch (\Throwable) {
            // Keep manual price when no active price exists.
        }
    }

    private function routeBase(): string
    {
        return $this->documentType === 'quotation' ? 'inventory.quotations' : 'inventory.sale-orders';
    }

    private function documentLabel(): string
    {
        return $this->documentType === 'quotation' ? 'Quotation' : 'Sale order';
    }
}

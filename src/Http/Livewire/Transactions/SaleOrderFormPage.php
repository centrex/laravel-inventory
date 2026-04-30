<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Enums\{PriceTierCode, SaleOrderStatus};
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Customer, Product, ProductPrice, SaleOrder, Warehouse, WarehouseProduct};
use Centrex\Inventory\Support\{CommercialTeamAccess, ErpIntegration};
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

    public string $currency = '';

    public ?float $exchange_rate = null;

    public float $tax_local = 0;

    public float $discount_local = 0;

    public float $shipping_local = 0;

    public string $coupon_code = '';

    public string $notes = '';

    public bool $show_notes = false;

    public bool $show_credit_override_options = false;

    public bool $credit_override = false;

    public string $credit_override_notes = '';

    public int $form_refresh_key = 0;

    public string $credit_limit_dialog_message = '';

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
            $this->currency = strtoupper((string) config('inventory.sale_defaults.currency', 'GBP'));

            $defaultName = (string) config('inventory.sale_defaults.warehouse_name', 'UK');
            $warehouse = Warehouse::query()->where('name', $defaultName)->first()
                ?? Warehouse::query()->orderBy('id')->first();

            if ($warehouse) {
                $this->warehouse_id = $warehouse->id;
                $this->syncWarehouseCurrency();
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

    public function toggleItemNotes(int $index): void
    {
        if (!isset($this->items[$index])) {
            return;
        }

        $this->items[$index]['show_notes'] = !((bool) ($this->items[$index]['show_notes'] ?? false));
    }

    public function toggleNotes(): void
    {
        $this->show_notes = !$this->show_notes;
    }

    public function toggleCreditOverrideOptions(): void
    {
        $this->show_credit_override_options = !$this->show_credit_override_options;
    }

    public function refreshItemPrice(int $index): void
    {
        $this->syncItemPrice($index);
    }

    public function updatedItems(mixed $value, string $key): void
    {
        if (preg_match('/^(\d+)\.(product_id|price_tier_code)$/', $key, $matches)) {
            $this->syncItemPrice((int) $matches[1]);
        }
    }

    public function save()
    {
        $this->prepareDerivedPricingFields();

        $validated = $this->validate($this->rules());
        $validated['document_type'] = $this->documentType;
        $validated['coupon_code'] = $this->recordId ? ($this->coupon_code ?: null) : null;
        $this->assertStockAvailability($validated['items']);

        try {
            if ($this->recordId) {
                $saleOrder = $this->updateOrder($validated);
                $this->dispatch('notify', type: 'success', message: "{$this->documentLabel()} {$saleOrder->so_number} updated.");

                return redirect()->route($this->routeBase() . '.edit', ['recordId' => $saleOrder->getKey()]);
            }

            $saleOrder = app(Inventory::class)->createSaleOrder($validated);
            $this->dispatch('notify', type: 'success', message: "{$this->documentLabel()} {$saleOrder->so_number} created.");

            return redirect()->route($this->routeBase() . '.edit', ['recordId' => $saleOrder->getKey()]);
        } catch (\InvalidArgumentException $exception) {
            if (!str_contains($exception->getMessage(), 'exceeds credit limit')) {
                throw $exception;
            }

            $this->credit_limit_dialog_message = $exception->getMessage();
            $this->show_credit_override_options = true;
            $this->dispatch('open-dialog', 'sale-order-credit-limit-dialog');

            return null;
        }
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
        $selectedProducts = $selectedProductIds === []
            ? collect()
            : Product::query()
                ->whereIn('id', $selectedProductIds)
                ->orderBy('name')
                ->get()
                ->keyBy('id');
        $selectedCustomer = $this->customer_id ? Customer::query()->find($this->customer_id) : null;

        return view('inventory::livewire.transactions.sale-order-form', [
            'warehouses'              => Warehouse::query()->orderBy('id')->get(),
            'priceTiers'              => PriceTierCode::options(),
            'selectedCustomer'        => $selectedCustomer,
            'selectedCustomerOptions' => $selectedCustomer
                ? [
                    $selectedCustomer->id => [
                        'label'    => (string) ($selectedCustomer->organization_name ?: $selectedCustomer->name),
                        'sublabel' => filled($selectedCustomer->phone) ? (string) $selectedCustomer->phone : null,
                    ],
                ]
                : [],
            'selectedProductOptions' => $selectedProducts->mapWithKeys(
                fn (Product $product): array => [
                    $product->id => [
                        'label'    => (string) $product->name,
                        'sublabel' => filled($product->barcode) ? (string) $product->barcode : null,
                    ],
                ],
            )->all(),
            'customerCreditSnapshot' => $selectedCustomer ? app(Inventory::class)->customerCreditSnapshot($selectedCustomer->id) : null,
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
        if ($property === 'warehouse_id') {
            $this->resetOrderForWarehouse();
            $this->syncWarehouseCurrency();

            return;
        }

        if ($property === 'price_tier_code') {
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
            'shipping_local'           => ['nullable', 'numeric'],
            'notes'                    => ['nullable', 'string'],
            'credit_override'          => ['nullable', 'boolean'],
            'credit_override_notes'    => ['nullable', 'string'],
            'items'                    => ['required', 'array', 'min:1'],
            'items.*.product_id'       => ['required', 'integer'],
            'items.*.qty_ordered'      => ['required', 'numeric', 'gt:0'],
            'items.*.price_tier_code'  => ['nullable', 'string'],
            'items.*.barcode'          => ['nullable', 'string'],
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
            'price_tier_code'  => '',
            'barcode'          => '',
            'unit_price_local' => null,
            'discount_pct'     => 0,
            'notes'            => '',
            'show_notes'       => false,
        ];
    }

    private function loadOrder(int $recordId): void
    {
        $query = SaleOrder::query()->with('items.product');
        CommercialTeamAccess::applySalesScope($query);
        $order = $query->findOrFail($recordId);

        $this->recordId = $order->getKey();
        $this->warehouse_id = $order->warehouse_id;
        $this->customer_id = $order->customer_id;
        $this->price_tier_code = $order->price_tier_code ?: $this->price_tier_code;
        $this->currency = $order->currency;
        $this->exchange_rate = $order->exchange_rate !== null ? (float) $order->exchange_rate : null;
        $this->tax_local = (float) $order->tax_local;
        $this->discount_local = (float) $order->discount_local;
        $this->shipping_local = (float) $order->shipping_local;
        $this->coupon_code = (string) ($order->coupon_code ?? '');
        $this->notes = (string) ($order->notes ?? '');
        $this->show_notes = false;
        $this->credit_override = (bool) $order->credit_override_required;
        $this->credit_override_notes = (string) ($order->credit_override_notes ?? '');
        $this->show_credit_override_options = $this->credit_override || $this->credit_override_notes !== '';
        $this->items = $order->items->map(fn ($item): array => [
            'product_id'       => $item->product_id,
            'qty_ordered'      => (float) $item->qty_ordered,
            'price_tier_code'  => $item->price_tier_code,
            'barcode'          => (string) ($item->product?->barcode ?? ''),
            'unit_price_local' => (float) $item->unit_price_local,
            'discount_pct'     => (float) $item->discount_pct,
            'notes'            => (string) ($item->notes ?? ''),
            'show_notes'       => (string) ($item->notes ?? '') !== '',
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
        $query = SaleOrder::query()->with('items');
        CommercialTeamAccess::applySalesScope($query);
        $saleOrder = $query->findOrFail($this->recordId);

        abort_unless($this->canEdit(), 403, 'This sale order can no longer be edited.');

        $products = Product::query()
            ->whereIn('id', collect($validated['items'])->pluck('product_id')->all())
            ->get()
            ->keyBy('id');

        $rate = (float) ($validated['exchange_rate'] ?? $saleOrder->exchange_rate ?? 1);
        $subtotal = 0.0;
        $cogs = 0.0;
        $itemsPayload = [];
        $inventory = app(Inventory::class);

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
                'unit_price_amount' => round($unitPrice * $rate, 4),
                'unit_cost_amount'  => (float) ($product?->meta['unit_cost_amount'] ?? 0),
                'discount_pct'      => $discountPct,
                'line_total_local'  => $lineTotal,
                'line_total_amount' => round($lineTotal * $rate, 4),
                'notes'             => $item['notes'] ?? '',
            ];
        }

        $orderedAt = $saleOrder->ordered_at ?? now();
        $coupon = $inventory->resolveCouponDiscount(
            $validated['coupon_code'] ?? null,
            $subtotal,
            $validated['currency'],
            $orderedAt,
            $validated['document_type'] ?? $saleOrder->document_type,
            $saleOrder->getKey(),
        );
        $subtotalAmount = round($subtotal * $rate, 4);
        $taxAmount = round((float) ($validated['tax_local'] ?? 0) * $rate, 4);
        $discountAmount = round((float) ($validated['discount_local'] ?? 0) * $rate, 4);
        $shippingAmount = round((float) ($validated['shipping_local'] ?? 0) * $rate, 4);
        $total = round(
            $subtotal
            + (float) ($validated['tax_local'] ?? 0)
            + (float) ($validated['shipping_local'] ?? 0)
            - (float) ($validated['discount_local'] ?? 0)
            - $coupon['coupon_discount_local'],
            4,
        );
        $totalAmount = round($subtotalAmount + $taxAmount + $shippingAmount - $discountAmount - $coupon['coupon_discount_amount'], 4);

        DB::transaction(function () use ($saleOrder, $validated, $subtotal, $subtotalAmount, $total, $totalAmount, $taxAmount, $discountAmount, $shippingAmount, $cogs, $itemsPayload, $coupon, $rate): void {
            $saleOrder->fill([
                'document_type'            => $validated['document_type'] ?? $saleOrder->document_type,
                'warehouse_id'             => $validated['warehouse_id'],
                'customer_id'              => $validated['customer_id'] ?? null,
                'coupon_id'                => $coupon['coupon_id'],
                'price_tier_code'          => $validated['price_tier_code'],
                'coupon_code'              => $coupon['coupon_code'],
                'coupon_name'              => $coupon['coupon_name'],
                'coupon_discount_type'     => $coupon['coupon_discount_type'],
                'coupon_discount_value'    => $coupon['coupon_discount_value'],
                'currency'                 => $validated['currency'],
                'exchange_rate'            => $rate,
                'subtotal_local'           => round($subtotal, 4),
                'subtotal_amount'          => $subtotalAmount,
                'tax_local'                => round((float) ($validated['tax_local'] ?? 0), 4),
                'tax_amount'               => $taxAmount,
                'discount_local'           => round((float) ($validated['discount_local'] ?? 0), 4),
                'discount_amount'          => $discountAmount,
                'shipping_local'           => round((float) ($validated['shipping_local'] ?? 0), 4),
                'shipping_amount'          => $shippingAmount,
                'coupon_discount_local'    => $coupon['coupon_discount_local'],
                'coupon_discount_amount'   => $coupon['coupon_discount_amount'],
                'total_local'              => $total,
                'total_amount'             => $totalAmount,
                'cogs_amount'              => $cogs,
                'notes'                    => $validated['notes'] ?? '',
                'credit_override_required' => (bool) ($validated['credit_override'] ?? false),
                'credit_override_notes'    => $validated['credit_override_notes'] ?? '',
            ])->save();

            $saleOrder->items()->delete();
            $saleOrder->items()->createMany($itemsPayload);
        });

        $saleOrder = $saleOrder->fresh(['items.product', 'customer', 'warehouse']);
        app(ErpIntegration::class)->syncSaleOrderDocument($saleOrder);

        return $saleOrder;
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
            $this->items[$index]['barcode'] = '';

            return;
        }

        $tierCode = $this->items[$index]['price_tier_code'] ?: $this->price_tier_code ?: PriceTierCode::B2B_RETAIL->value;

        try {
            $inventory = app(Inventory::class);
            $price = $this->resolvePriceForLine($inventory, $productId, $tierCode);
            $product = Product::query()->find($productId);
            $currency = $this->warehouseCurrency();

            $this->items[$index]['barcode'] = (string) ($product?->barcode ?? '');
            $this->currency = $currency;
            $this->exchange_rate = $this->exchangeRateForCurrency($currency);
            $this->items[$index]['unit_price_local'] = $this->resolvedUnitPriceLocal($price, $inventory, $currency);
        } catch (\Throwable) {
            // Keep manual price when no active price exists.
        }
    }

    private function resolvePriceForLine(Inventory $inventory, int $productId, string $tierCode): ProductPrice
    {
        try {
            return $inventory->resolvePrice($productId, $tierCode, (int) $this->warehouse_id);
        } catch (\Throwable) {
            $query = ProductPrice::query()
                ->where('product_id', $productId)
                ->where('is_active', true)
                ->where(fn ($builder) => $builder->whereNull('effective_from')->orWhere('effective_from', '<=', now()->toDateString()))
                ->where(fn ($builder) => $builder->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString()));

            $price = (clone $query)
                ->where('price_tier_code', $tierCode)
                ->where('warehouse_id', $this->warehouse_id)
                ->latest()
                ->first();
            $price ??= (clone $query)
                ->where('price_tier_code', $tierCode)
                ->whereNull('warehouse_id')
                ->latest()
                ->first();
            $price ??= (clone $query)
                ->where('warehouse_id', $this->warehouse_id)
                ->latest()
                ->first();
            $price ??= (clone $query)
                ->whereNull('warehouse_id')
                ->latest()
                ->first();

            if ($price) {
                return $price;
            }

            $product = Product::query()->find($productId);
            $defaultPrice = (float) ($product?->meta['default_price'] ?? $product?->meta['price'] ?? 0);

            return new ProductPrice([
                'price_amount' => $defaultPrice,
                'price_local'  => $defaultPrice,
            ]);
        }
    }

    private function resolvedUnitPriceLocal(ProductPrice $price, Inventory $inventory, string $currency): float
    {
        if ($price->price_local !== null) {
            return (float) $price->price_local;
        }

        $priceAmount = (float) ($price->price_amount ?? 0);

        try {
            return $inventory->convertFromBase($priceAmount, $currency);
        } catch (\Throwable) {
            return $priceAmount;
        }
    }

    private function prepareDerivedPricingFields(): void
    {
        foreach (array_keys($this->items) as $index) {
            if (($this->items[$index]['unit_price_local'] ?? null) === null || $this->items[$index]['unit_price_local'] === '') {
                $this->syncItemPrice($index);

                continue;
            }

            $this->syncCurrencyFromItemPrice($index);
        }

        $this->syncWarehouseCurrency();
        $this->exchange_rate ??= $this->exchangeRateForCurrency($this->currency);
    }

    private function syncCurrencyFromItemPrice(int $index): void
    {
        $productId = (int) ($this->items[$index]['product_id'] ?? 0);

        if (!$productId || !$this->warehouse_id) {
            return;
        }

        $tierCode = $this->items[$index]['price_tier_code'] ?: $this->price_tier_code ?: PriceTierCode::B2B_RETAIL->value;

        try {
            $this->resolvePriceForLine(app(Inventory::class), $productId, $tierCode);
            $currency = $this->warehouseCurrency();

            $this->currency = $currency;
            $this->exchange_rate = $this->exchangeRateForCurrency($currency);
        } catch (\Throwable) {
            // Manual lines can still be submitted with the current derived currency.
        }
    }

    private function resetOrderForWarehouse(): void
    {
        $this->customer_id = null;
        $this->price_tier_code = PriceTierCode::B2B_RETAIL->value;
        $this->tax_local = 0;
        $this->discount_local = 0;
        $this->shipping_local = 0;
        $this->coupon_code = '';
        $this->notes = '';
        $this->show_notes = false;
        $this->credit_override = false;
        $this->credit_override_notes = '';
        $this->show_credit_override_options = false;
        $this->items = [$this->blankItem()];
        $this->form_refresh_key++;
        $this->resetErrorBag();
        $this->resetValidation();
    }

    private function syncWarehouseCurrency(): void
    {
        $currency = $this->warehouseCurrency();

        $this->currency = $currency;
        $this->exchange_rate = $this->exchangeRateForCurrency($currency);
    }

    private function warehouseCurrency(): string
    {
        $currency = $this->warehouse_id
            ? Warehouse::query()->whereKey($this->warehouse_id)->value('currency')
            : null;

        return strtoupper((string) ($currency ?: config('inventory.sale_defaults.currency', 'GBP')));
    }

    private function exchangeRateForCurrency(string $currency): ?float
    {
        try {
            return app(Inventory::class)->getExchangeRate($currency);
        } catch (\Throwable) {
            return null;
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

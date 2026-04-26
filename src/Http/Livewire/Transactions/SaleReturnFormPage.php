<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Customer, Product, SaleOrder, SaleReturnItem, Warehouse};
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class SaleReturnFormPage extends Component
{
    public ?int $sale_order_id = null;

    public ?int $warehouse_id = null;

    public ?int $customer_id = null;

    public ?string $returned_at = null;

    public string $notes = '';

    public array $items = [];

    public function mount(): void
    {
        $this->returned_at = now()->toDateString();
        $this->items = [$this->blankItem()];
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
        $validated = $this->validate([
            'sale_order_id'              => ['nullable', 'integer'],
            'warehouse_id'               => ['required', 'integer'],
            'customer_id'                => ['nullable', 'integer'],
            'returned_at'                => ['nullable', 'date'],
            'notes'                      => ['nullable', 'string'],
            'items'                      => ['required', 'array', 'min:1'],
            'items.*.sale_order_item_id' => ['nullable', 'integer'],
            'items.*.product_id'         => ['required', 'integer'],
            'items.*.variant_id'         => ['nullable', 'integer'],
            'items.*.qty_returned'       => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price_amount'  => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_cost_amount'   => ['nullable', 'numeric', 'min:0'],
            'items.*.notes'              => ['nullable', 'string'],
        ]);

        $validated['items'] = collect($validated['items'])
            ->map(function (array $item): array {
                $sourceItemId = (int) ($item['sale_order_item_id'] ?? 0);
                $sourceItem = $sourceItemId > 0 ? ($this->availableProducts()[$sourceItemId] ?? null) : null;

                if ($sourceItem) {
                    $item['product_id'] = $sourceItem['product_id'];
                    $item['variant_id'] = $sourceItem['variant_id'];
                }

                return $item;
            })
            ->all();

        try {
            $saleReturn = app(Inventory::class)->createSaleReturn($validated);
            app(Inventory::class)->postSaleReturn((int) $saleReturn->getKey());
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return null;
        }

        $this->dispatch('notify', type: 'success', message: "Sale return {$saleReturn->return_number} posted.");

        return redirect()->route('inventory.sale-returns.show', ['recordId' => $saleReturn->getKey()]);
    }

    public function render(): View
    {
        $selectedOrder = $this->selectedSaleOrder();
        $availableProducts = $this->availableProducts();

        return view('inventory::livewire.transactions.sale-return-form', [
            'saleOrders' => SaleOrder::query()->with('customer')->where('document_type', 'order')->orderByDesc('ordered_at')->limit(100)->get(),
            'warehouses' => Warehouse::query()->orderBy('name')->get(),
            'customers'  => Customer::query()->orderBy('name')->get(),
            'products'   => $selectedOrder
                ? collect($availableProducts)->sortBy('name')->values()->all()
                : Product::query()->orderBy('name')->get()->all(),
            'selectedOrder'     => $selectedOrder,
            'availableProducts' => $availableProducts,
        ]);
    }

    public function updatedSaleOrderId($value): void
    {
        $order = $value ? $this->selectedSaleOrder() : null;

        $this->customer_id = $order?->customer_id;
        $this->warehouse_id = $order?->warehouse_id ?? $this->warehouse_id;
        $this->items = [$this->blankItem()];
    }

    public function updatedItems($value, string $name): void
    {
        [$index, $field] = explode('.', $name);
        $index = (int) $index;

        if (!isset($this->items[$index])) {
            return;
        }

        if (in_array($field, ['product_id', 'sale_order_item_id'], true)) {
            $this->hydrateItemFromSourceOrder($index);

            return;
        }

        if ($field === 'qty_returned') {
            $this->clampItemQuantity($index);
        }
    }

    private function blankItem(): array
    {
        return [
            'sale_order_item_id' => null,
            'product_id'         => null,
            'variant_id'         => null,
            'qty_returned'       => 1,
            'unit_price_amount'  => null,
            'unit_cost_amount'   => null,
            'notes'              => '',
        ];
    }

    private function selectedSaleOrder(): ?SaleOrder
    {
        if (!$this->sale_order_id) {
            return null;
        }

        return SaleOrder::query()
            ->with(['customer', 'warehouse', 'items.product', 'items.variant'])
            ->where('document_type', 'order')
            ->find($this->sale_order_id);
    }

    private function availableProducts(): array
    {
        $saleOrder = $this->selectedSaleOrder();

        if (!$saleOrder) {
            return [];
        }

        $returnedByItem = SaleReturnItem::query()
            ->whereIn('sale_order_item_id', $saleOrder->items->pluck('id')->all())
            ->selectRaw('sale_order_item_id, SUM(qty_returned) as qty_returned')
            ->groupBy('sale_order_item_id')
            ->pluck('qty_returned', 'sale_order_item_id');

        return $saleOrder->items
            ->map(function ($item) use ($returnedByItem): array {
                $maxQty = max(0.0, round(
                    (float) $item->qty_fulfilled - (float) ($returnedByItem[$item->getKey()] ?? 0),
                    4,
                ));

                $productLabel = $item->variant
                    ? ($item->variant->display_name ?: ($item->product?->name ?? 'Product'))
                    : ($item->product?->name ?? 'Product');

                return [
                    'id'                => (int) $item->getKey(),
                    'product_id'        => (int) $item->product_id,
                    'variant_id'        => $item->variant_id !== null ? (int) $item->variant_id : null,
                    'name'              => $productLabel,
                    'max_qty'           => $maxQty,
                    'unit_price_amount' => (float) $item->unit_price_amount,
                    'unit_cost_amount'  => (float) $item->unit_cost_amount,
                ];
            })
            ->filter(fn (array $item): bool => $item['max_qty'] > 0)
            ->keyBy('id')
            ->all();
    }

    private function hydrateItemFromSourceOrder(int $index): void
    {
        $sourceItemId = (int) ($this->items[$index]['sale_order_item_id'] ?? 0);
        $product = $this->selectedSaleOrder()
            ? ($this->availableProducts()[$sourceItemId] ?? null)
            : ($this->availableProducts()[(int) ($this->items[$index]['product_id'] ?? 0)] ?? null);

        if (!$product) {
            $this->items[$index]['sale_order_item_id'] = null;
            $this->items[$index]['product_id'] = null;
            $this->items[$index]['variant_id'] = null;
            $this->items[$index]['unit_price_amount'] = null;
            $this->items[$index]['unit_cost_amount'] = null;

            return;
        }

        $this->items[$index]['product_id'] = $product['product_id'] ?? (int) ($this->items[$index]['product_id'] ?? 0);
        $this->items[$index]['variant_id'] = $product['variant_id'] ?? null;
        $this->items[$index]['unit_price_amount'] = $product['unit_price_amount'];
        $this->items[$index]['unit_cost_amount'] = $product['unit_cost_amount'];
        $this->items[$index]['qty_returned'] = min((float) ($this->items[$index]['qty_returned'] ?? 1), $product['max_qty']);
    }

    private function clampItemQuantity(int $index): void
    {
        $selectedKey = $this->selectedSaleOrder()
            ? (int) ($this->items[$index]['sale_order_item_id'] ?? 0)
            : (int) ($this->items[$index]['product_id'] ?? 0);
        $product = $this->availableProducts()[$selectedKey] ?? null;

        if (!$product) {
            return;
        }

        $qty = max(0.0001, (float) ($this->items[$index]['qty_returned'] ?? 1));
        $this->items[$index]['qty_returned'] = min($qty, $product['max_qty']);
    }
}

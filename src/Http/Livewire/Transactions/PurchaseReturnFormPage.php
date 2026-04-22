<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Product, PurchaseOrder, PurchaseReturnItem, Supplier, Warehouse};
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PurchaseReturnFormPage extends Component
{
    public ?int $purchase_order_id = null;

    public ?int $warehouse_id = null;

    public ?int $supplier_id = null;

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
            'purchase_order_id'        => ['nullable', 'integer'],
            'warehouse_id'             => ['required', 'integer'],
            'supplier_id'              => ['required', 'integer'],
            'returned_at'              => ['nullable', 'date'],
            'notes'                    => ['nullable', 'string'],
            'items'                    => ['required', 'array', 'min:1'],
            'items.*.product_id'       => ['required', 'integer'],
            'items.*.qty_returned'     => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes'            => ['nullable', 'string'],
        ]);

        try {
            $purchaseReturn = app(Inventory::class)->createPurchaseReturn($validated);
            app(Inventory::class)->postPurchaseReturn((int) $purchaseReturn->getKey());
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return null;
        }

        session()->flash('inventory.status', "Purchase return {$purchaseReturn->return_number} posted.");

        return redirect()->route('inventory.purchase-returns.show', ['recordId' => $purchaseReturn->getKey()]);
    }

    public function render(): View
    {
        $selectedOrder = $this->selectedPurchaseOrder();
        $availableProducts = $this->availableProducts();

        return view('inventory::livewire.transactions.purchase-return-form', [
            'purchaseOrders' => PurchaseOrder::query()->with('supplier')->where('document_type', 'order')->orderByDesc('ordered_at')->limit(100)->get(),
            'warehouses'     => Warehouse::query()->orderBy('name')->get(),
            'suppliers'      => Supplier::query()->orderBy('name')->get(),
            'products'       => $selectedOrder
                ? collect($availableProducts)->sortBy('name')->values()->all()
                : Product::query()->orderBy('name')->get()->all(),
            'selectedOrder'     => $selectedOrder,
            'availableProducts' => $availableProducts,
        ]);
    }

    public function updatedPurchaseOrderId($value): void
    {
        $order = $value ? $this->selectedPurchaseOrder() : null;

        $this->supplier_id = $order?->supplier_id;
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

        if ($field === 'product_id') {
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
            'product_id'       => null,
            'qty_returned'     => 1,
            'unit_cost_amount' => null,
            'notes'            => '',
        ];
    }

    private function selectedPurchaseOrder(): ?PurchaseOrder
    {
        if (!$this->purchase_order_id) {
            return null;
        }

        return PurchaseOrder::query()
            ->with(['supplier', 'warehouse', 'items.product'])
            ->where('document_type', 'order')
            ->find($this->purchase_order_id);
    }

    private function availableProducts(): array
    {
        $purchaseOrder = $this->selectedPurchaseOrder();

        if (!$purchaseOrder) {
            return [];
        }

        $returnedByItem = PurchaseReturnItem::query()
            ->whereIn('purchase_order_item_id', $purchaseOrder->items->pluck('id')->all())
            ->selectRaw('purchase_order_item_id, SUM(qty_returned) as qty_returned')
            ->groupBy('purchase_order_item_id')
            ->pluck('qty_returned', 'purchase_order_item_id');

        return $purchaseOrder->items
            ->map(function ($item) use ($returnedByItem): array {
                $maxQty = max(0.0, round(
                    (float) $item->qty_received - (float) ($returnedByItem[$item->getKey()] ?? 0),
                    4,
                ));

                return [
                    'id'               => (int) $item->product_id,
                    'name'             => $item->product?->name ?? 'Product',
                    'max_qty'          => $maxQty,
                    'unit_cost_amount' => (float) $item->unit_price_amount,
                ];
            })
            ->filter(fn (array $item): bool => $item['max_qty'] > 0)
            ->keyBy('id')
            ->all();
    }

    private function hydrateItemFromSourceOrder(int $index): void
    {
        $productId = (int) ($this->items[$index]['product_id'] ?? 0);
        $product = $this->availableProducts()[$productId] ?? null;

        if (!$product) {
            $this->items[$index]['product_id'] = null;
            $this->items[$index]['unit_cost_amount'] = null;

            return;
        }

        $this->items[$index]['unit_cost_amount'] = $product['unit_cost_amount'];
        $this->items[$index]['qty_returned'] = min((float) ($this->items[$index]['qty_returned'] ?? 1), $product['max_qty']);
    }

    private function clampItemQuantity(int $index): void
    {
        $productId = (int) ($this->items[$index]['product_id'] ?? 0);
        $product = $this->availableProducts()[$productId] ?? null;

        if (!$product) {
            return;
        }

        $qty = max(0.0001, (float) ($this->items[$index]['qty_returned'] ?? 1));
        $this->items[$index]['qty_returned'] = min($qty, $product['max_qty']);
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Enums\{PriceTierCode, PurchaseOrderStatus};
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Product, PurchaseOrder, Supplier, Warehouse, WarehouseProduct};
use Centrex\Inventory\Support\{CommercialTeamAccess, ErpIntegration};
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PurchaseOrderFormPage extends Component
{
    public string $documentType = 'order';

    public ?int $recordId = null;

    public ?int $warehouse_id = null;

    public ?int $supplier_id = null;

    public string $currency = 'BDT';

    public ?float $exchange_rate = null;

    public float $tax_local = 0;

    public float $shipping_local = 0;

    public float $other_charges_amount = 0;

    public ?string $expected_at = null;

    public string $notes = '';

    public bool $show_notes = false;

    public int $form_refresh_key = 0;

    public array $items = [];

    public function mount(int|string|null $recordId = null, string $documentType = 'order'): void
    {
        $recordId = is_numeric($recordId) && (int) $recordId > 0 ? (int) $recordId : null;

        $this->documentType = $documentType === 'requisition' ? 'requisition' : 'order';
        $this->items = [$this->blankItem()];

        if ($recordId !== null) {
            $this->loadOrder($recordId);
        } else {
            $defaultName = (string) config('inventory.purchase_defaults.warehouse_name', 'UK');
            $first = Warehouse::query()->where('name', $defaultName)->first()
                ?? Warehouse::query()->orderBy('id')->first();

            if ($first) {
                $this->warehouse_id = $first->id;
                $this->syncWarehouseCurrency();
            }
        }
    }

    public function updated(string $property): void
    {
        if ($property === 'warehouse_id') {
            if (!$this->recordId) {
                $this->resetOrderForWarehouse();
            }

            $this->syncWarehouseCurrency();

            return;
        }

        if (preg_match('/^items\.(\d+)\.product_id$/', $property, $matches)) {
            $this->syncItemPrice((int) $matches[1]);
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

    public function save()
    {
        $validated = $this->validate($this->rules());
        $validated['document_type'] = $this->documentType;

        if ($this->recordId) {
            $purchaseOrder = $this->updateOrder($validated);
            $this->dispatch('notify', type: 'success', message: "{$this->documentLabel()} {$purchaseOrder->po_number} updated.");

            return redirect()->route($this->routeBase() . '.edit', ['recordId' => $purchaseOrder->getKey()]);
        }

        $purchaseOrder = app(Inventory::class)->createPurchaseOrder($validated);
        $this->dispatch('notify', type: 'success', message: "{$this->documentLabel()} {$purchaseOrder->po_number} created.");

        return redirect()->route($this->routeBase() . '.edit', ['recordId' => $purchaseOrder->getKey()]);
    }

    public function render(): View
    {
        $selectedProductIds = collect($this->items)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->all();
        $onHandStock = $this->warehouse_id
            ? WarehouseProduct::query()
                ->where('warehouse_id', $this->warehouse_id)
                ->get()
                ->keyBy('product_id')
            : collect();
        $selectedProducts = $selectedProductIds === []
            ? collect()
            : Product::query()
                ->whereIn('id', $selectedProductIds)
                ->orderBy('name')
                ->get()
                ->keyBy('id');
        $selectedSupplier = $this->supplier_id ? Supplier::query()->find($this->supplier_id) : null;

        return view('inventory::livewire.transactions.purchase-order-form', [
            'warehouses'              => Warehouse::query()->orderBy('id')->get(),
            'selectedSupplierOptions' => $selectedSupplier
                ? [$selectedSupplier->id => $selectedSupplier->name]
                : [],
            'selectedProductOptions' => $selectedProducts->mapWithKeys(
                fn (Product $product): array => [$product->id => $product->name],
            )->all(),
            'onHandStock'   => $onHandStock,
            'isEditing'     => $this->recordId !== null,
            'editable'      => $this->canEdit(),
            'record'        => $this->recordId ? PurchaseOrder::query()->with(['supplier', 'warehouse'])->find($this->recordId) : null,
            'documentLabel' => $this->documentLabel(),
            'routeBase'     => $this->routeBase(),
        ]);
    }

    private function syncItemPrice(int $index): void
    {
        $productId = (int) ($this->items[$index]['product_id'] ?? 0);

        if (!$productId) {
            return;
        }

        try {
            $price = app(Inventory::class)->resolvePrice($productId, PriceTierCode::BASE->value, (int) ($this->warehouse_id ?? 0));
            $this->items[$index]['unit_price_local'] = (float) ($price->price_local ?: app(Inventory::class)->convertFromBase((float) $price->price_amount, $this->currency ?: 'BDT'));
        } catch (\Throwable) {
            $defaultPrice = (float) (Product::find($productId)?->meta['default_price'] ?? 0);

            if ($defaultPrice > 0) {
                $this->items[$index]['unit_price_local'] = $defaultPrice;
            }
        }
    }

    private function rules(): array
    {
        return [
            'warehouse_id'             => ['required', 'integer'],
            'supplier_id'              => ['required', 'integer'],
            'currency'                 => ['required', 'string', 'size:3'],
            'exchange_rate'            => ['nullable', 'numeric', 'gt:0'],
            'tax_local'                => ['nullable', 'numeric'],
            'shipping_local'           => ['nullable', 'numeric'],
            'other_charges_amount'     => ['nullable', 'numeric'],
            'expected_at'              => ['nullable', 'date'],
            'notes'                    => ['nullable', 'string'],
            'items'                    => ['required', 'array', 'min:1'],
            'items.*.product_id'       => ['required', 'integer'],
            'items.*.qty_ordered'      => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price_local' => ['required', 'numeric', 'min:0'],
            'items.*.notes'            => ['nullable', 'string'],
        ];
    }

    private function blankItem(): array
    {
        return [
            'product_id'       => null,
            'qty_ordered'      => 1,
            'unit_price_local' => 0,
            'notes'            => '',
            'show_notes'       => false,
        ];
    }

    private function loadOrder(int $recordId): void
    {
        $query = PurchaseOrder::query()->with('items');
        CommercialTeamAccess::applyPurchaseScope($query);
        $order = $query->findOrFail($recordId);

        $this->recordId = $order->getKey();
        $this->warehouse_id = $order->warehouse_id;
        $this->supplier_id = $order->supplier_id;
        $this->currency = $order->currency;
        $this->exchange_rate = $order->exchange_rate !== null ? (float) $order->exchange_rate : null;
        $this->tax_local = (float) $order->tax_local;
        $this->shipping_local = (float) $order->shipping_local;
        $this->other_charges_amount = (float) $order->other_charges_amount;
        $this->expected_at = $order->expected_at?->format('Y-m-d');
        $this->notes = (string) ($order->notes ?? '');
        $this->show_notes = false;
        $this->items = $order->items->map(fn ($item): array => [
            'product_id'       => $item->product_id,
            'qty_ordered'      => (float) $item->qty_ordered,
            'unit_price_local' => (float) $item->unit_price_local,
            'notes'            => (string) ($item->notes ?? ''),
            'show_notes'       => (string) ($item->notes ?? '') !== '',
        ])->all();
    }

    private function canEdit(): bool
    {
        if (!$this->recordId) {
            return true;
        }

        $order = PurchaseOrder::query()->find($this->recordId);

        return in_array($order?->status?->value, [PurchaseOrderStatus::DRAFT->value, PurchaseOrderStatus::SUBMITTED->value], true);
    }

    private function updateOrder(array $validated): PurchaseOrder
    {
        $query = PurchaseOrder::query()->with('items');
        CommercialTeamAccess::applyPurchaseScope($query);
        $purchaseOrder = $query->findOrFail($this->recordId);

        abort_unless($this->canEdit(), 403, 'This purchase order can no longer be edited.');

        $subtotal = 0.0;
        $itemsPayload = [];

        foreach ($validated['items'] as $item) {
            $qty = round((float) $item['qty_ordered'], 4);
            $unitPrice = round((float) $item['unit_price_local'], 4);
            $lineTotal = round($qty * $unitPrice, 4);
            $subtotal += $lineTotal;

            $itemsPayload[] = [
                'product_id'        => (int) $item['product_id'],
                'qty_ordered'       => $qty,
                'qty_received'      => 0,
                'unit_price_local'  => $unitPrice,
                'unit_price_amount' => $unitPrice,
                'line_total_local'  => $lineTotal,
                'line_total_amount' => $lineTotal,
                'notes'             => $item['notes'] ?? '',
            ];
        }

        $total = round(
            $subtotal
            + (float) ($validated['tax_local'] ?? 0)
            + (float) ($validated['shipping_local'] ?? 0)
            + (float) ($validated['other_charges_amount'] ?? 0),
            4,
        );

        DB::transaction(function () use ($purchaseOrder, $validated, $subtotal, $total, $itemsPayload): void {
            $purchaseOrder->fill([
                'document_type'        => $validated['document_type'] ?? $purchaseOrder->document_type,
                'warehouse_id'         => $validated['warehouse_id'],
                'supplier_id'          => $validated['supplier_id'],
                'currency'             => $validated['currency'],
                'exchange_rate'        => $validated['exchange_rate'] ?? 1,
                'subtotal_local'       => round($subtotal, 4),
                'subtotal_amount'      => round($subtotal, 4),
                'tax_local'            => round((float) ($validated['tax_local'] ?? 0), 4),
                'tax_amount'           => round((float) ($validated['tax_local'] ?? 0), 4),
                'shipping_local'       => round((float) ($validated['shipping_local'] ?? 0), 4),
                'shipping_amount'      => round((float) ($validated['shipping_local'] ?? 0), 4),
                'other_charges_amount' => round((float) ($validated['other_charges_amount'] ?? 0), 4),
                'total_local'          => $total,
                'total_amount'         => $total,
                'expected_at'          => $validated['expected_at'] ?? null,
                'notes'                => $validated['notes'] ?? '',
            ])->save();

            $purchaseOrder->items()->delete();
            $purchaseOrder->items()->createMany($itemsPayload);
        });

        $purchaseOrder = $purchaseOrder->fresh(['items.product', 'supplier', 'warehouse']);
        app(ErpIntegration::class)->syncPurchaseOrderDocument($purchaseOrder);

        return $purchaseOrder;
    }

    private function resetOrderForWarehouse(): void
    {
        $this->supplier_id = null;
        $this->tax_local = 0;
        $this->shipping_local = 0;
        $this->other_charges_amount = 0;
        $this->expected_at = null;
        $this->notes = '';
        $this->show_notes = false;
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

        return strtoupper((string) ($currency ?: config('inventory.purchase_defaults.currency', 'GBP')));
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
        return $this->documentType === 'requisition' ? 'inventory.requisitions' : 'inventory.purchase-orders';
    }

    private function documentLabel(): string
    {
        return $this->documentType === 'requisition' ? 'Requisition' : 'Purchase order';
    }
}

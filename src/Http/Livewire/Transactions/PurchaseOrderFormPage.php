<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Livewire\Transactions;

use Centrex\Inventory\Enums\PurchaseOrderStatus;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Models\{Product, PurchaseOrder, Supplier, Warehouse};
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

    public array $items = [];

    public function mount(?int $recordId = null, string $documentType = 'order'): void
    {
        $this->documentType = $documentType === 'requisition' ? 'requisition' : 'order';
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

    public function save()
    {
        $validated = $this->validate($this->rules());
        $validated['document_type'] = $this->documentType;

        if ($this->recordId) {
            $purchaseOrder = $this->updateOrder($validated);
            session()->flash('inventory.status', "{$this->documentLabel()} {$purchaseOrder->po_number} updated.");

            return redirect()->route($this->routeBase() . '.edit', ['recordId' => $purchaseOrder->getKey()]);
        }

        $purchaseOrder = app(Inventory::class)->createPurchaseOrder($validated);
        session()->flash('inventory.status', "{$this->documentLabel()} {$purchaseOrder->po_number} created.");

        return redirect()->route($this->routeBase() . '.edit', ['recordId' => $purchaseOrder->getKey()]);
    }

    public function render(): View
    {
        return view('inventory::livewire.transactions.purchase-order-form', [
            'warehouses'    => Warehouse::query()->orderBy('name')->get(),
            'suppliers'     => Supplier::query()->orderBy('name')->get(),
            'products'      => Product::query()->orderBy('name')->get(),
            'isEditing'     => $this->recordId !== null,
            'editable'      => $this->canEdit(),
            'record'        => $this->recordId ? PurchaseOrder::query()->with(['supplier', 'warehouse'])->find($this->recordId) : null,
            'documentLabel' => $this->documentLabel(),
            'routeBase'     => $this->routeBase(),
        ]);
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
        ];
    }

    private function loadOrder(int $recordId): void
    {
        $order = PurchaseOrder::query()->with('items')->findOrFail($recordId);

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
        $this->items = $order->items->map(fn ($item): array => [
            'product_id'       => $item->product_id,
            'qty_ordered'      => (float) $item->qty_ordered,
            'unit_price_local' => (float) $item->unit_price_local,
            'notes'            => (string) ($item->notes ?? ''),
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
        $purchaseOrder = PurchaseOrder::query()->with('items')->findOrFail($this->recordId);

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

        return $purchaseOrder->fresh(['items', 'supplier', 'warehouse']);
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

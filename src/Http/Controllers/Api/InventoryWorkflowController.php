<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Controllers\Api;

use Centrex\Inventory\Enums\PriceTierCode;
use Centrex\Inventory\Inventory;
use Centrex\Inventory\Support\CartCheckoutService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Routing\Controller;

class InventoryWorkflowController extends Controller
{
    public function __construct(
        private readonly Inventory $inventory,
        private readonly CartCheckoutService $cartCheckoutService,
    ) {}

    public function setExchangeRate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'currency' => ['required', 'string', 'size:3'],
            'rate'     => ['required', 'numeric', 'gt:0'],
            'date'     => ['nullable', 'date'],
            'source'   => ['nullable', 'string', 'max:30'],
        ]);

        return response()->json(
            $this->inventory->setExchangeRate(
                $validated['currency'],
                (float) $validated['rate'],
                $validated['date'] ?? null,
                $validated['source'] ?? 'manual',
            ),
        );
    }

    public function convertToBdt(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount'   => ['required', 'numeric'],
            'currency' => ['required', 'string', 'size:3'],
            'date'     => ['nullable', 'date'],
        ]);

        return response()->json([
            'amount_base' => $this->inventory->convertToBase((float) $validated['amount'], $validated['currency'], $validated['date'] ?? null),
        ]);
    }

    public function convertFromBdt(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount_base' => ['required', 'numeric'],
            'currency'    => ['required', 'string', 'size:3'],
            'date'        => ['nullable', 'date'],
        ]);

        return response()->json([
            'amount' => $this->inventory->convertFromBase((float) $validated['amount_base'], $validated['currency'], $validated['date'] ?? null),
        ]);
    }

    public function setPrice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id'     => ['required', 'integer'],
            'tier_code'      => ['required', 'string'],
            'price_amount'   => ['required', 'numeric', 'min:0'],
            'warehouse_id'   => ['nullable', 'integer'],
            'price_local'    => ['nullable', 'numeric', 'min:0'],
            'currency'       => ['nullable', 'string', 'size:3'],
            'effective_from' => ['nullable', 'date'],
            'effective_to'   => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active'      => ['nullable', 'boolean'],
        ]);

        $price = $this->inventory->setPrice(
            (int) $validated['product_id'],
            $validated['tier_code'],
            (float) $validated['price_amount'],
            $validated['warehouse_id'] ?? null,
            $validated,
        );

        return response()->json($price->fresh());
    }

    public function resolvePrice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id'   => ['required', 'integer'],
            'tier_code'    => ['required', 'string'],
            'warehouse_id' => ['required', 'integer'],
            'date'         => ['nullable', 'date'],
        ]);

        return response()->json(
            $this->inventory->resolvePrice(
                (int) $validated['product_id'],
                $validated['tier_code'],
                (int) $validated['warehouse_id'],
                $validated['date'] ?? null,
            ),
        );
    }

    public function priceSheet(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id'   => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'date'         => ['nullable', 'date'],
        ]);

        return response()->json(
            $this->inventory->getPriceSheet(
                (int) $validated['product_id'],
                (int) $validated['warehouse_id'],
                $validated['date'] ?? null,
            ),
        );
    }

    public function stockLevels(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer'],
        ]);

        return response()->json(
            $this->inventory->getStockLevels((int) $validated['warehouse_id']),
        );
    }

    public function stockValuation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'integer'],
        ]);

        return response()->json(
            $this->inventory->stockValuationReport($validated['warehouse_id'] ?? null),
        );
    }

    public function movementHistory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id'   => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'from'         => ['nullable', 'date'],
            'to'           => ['nullable', 'date'],
        ]);

        return response()->json(
            $this->inventory->getMovementHistory(
                (int) $validated['product_id'],
                (int) $validated['warehouse_id'],
                $validated['from'] ?? null,
                $validated['to'] ?? null,
            ),
        );
    }

    public function createPurchaseOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id'             => ['required', 'integer'],
            'supplier_id'              => ['required', 'integer'],
            'currency'                 => ['required', 'string', 'size:3'],
            'exchange_rate'            => ['nullable', 'numeric', 'gt:0'],
            'tax_local'                => ['nullable', 'numeric'],
            'shipping_local'           => ['nullable', 'numeric'],
            'other_charges_amount'     => ['nullable', 'numeric'],
            'ordered_at'               => ['nullable', 'date'],
            'expected_at'              => ['nullable', 'date'],
            'notes'                    => ['nullable', 'string'],
            'created_by'               => ['nullable', 'integer'],
            'items'                    => ['required', 'array', 'min:1'],
            'items.*.product_id'       => ['required', 'integer'],
            'items.*.qty_ordered'      => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price_local' => ['required', 'numeric', 'min:0'],
            'items.*.notes'            => ['nullable', 'string'],
        ]);

        return response()->json($this->inventory->createPurchaseOrder($validated), 201);
    }

    public function submitPurchaseOrder(int $purchaseOrderId): JsonResponse
    {
        return response()->json($this->inventory->submitPurchaseOrder($purchaseOrderId));
    }

    public function confirmPurchaseOrder(int $purchaseOrderId): JsonResponse
    {
        return response()->json($this->inventory->confirmPurchaseOrder($purchaseOrderId));
    }

    public function createStockReceipt(Request $request, int $purchaseOrderId): JsonResponse
    {
        $validated = $request->validate([
            'received_at'                    => ['nullable', 'date'],
            'notes'                          => ['nullable', 'string'],
            'created_by'                     => ['nullable', 'integer'],
            'items'                          => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer'],
            'items.*.qty_received'           => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost_local'        => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json(
            $this->inventory->createStockReceipt($purchaseOrderId, $validated['items'], $validated),
            201,
        );
    }

    public function postStockReceipt(int $stockReceiptId): JsonResponse
    {
        return response()->json($this->inventory->postStockReceipt($stockReceiptId));
    }

    public function voidStockReceipt(int $stockReceiptId): JsonResponse
    {
        return response()->json($this->inventory->voidStockReceipt($stockReceiptId));
    }

    public function createSaleOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id'             => ['required', 'integer'],
            'customer_id'              => ['nullable', 'integer'],
            'price_tier_code'          => ['nullable', 'string'],
            'currency'                 => ['required', 'string', 'size:3'],
            'exchange_rate'            => ['nullable', 'numeric', 'gt:0'],
            'tax_local'                => ['nullable', 'numeric'],
            'discount_local'           => ['nullable', 'numeric'],
            'ordered_at'               => ['nullable', 'date'],
            'notes'                    => ['nullable', 'string'],
            'created_by'               => ['nullable', 'integer'],
            'items'                    => ['required', 'array', 'min:1'],
            'items.*.product_id'       => ['required', 'integer'],
            'items.*.qty_ordered'      => ['required', 'numeric', 'gt:0'],
            'items.*.price_tier_code'  => ['nullable', 'string'],
            'items.*.unit_price_local' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_pct'     => ['nullable', 'numeric', 'min:0'],
            'items.*.notes'            => ['nullable', 'string'],
        ]);

        $validated['price_tier_code'] ??= PriceTierCode::RETAIL->value;

        return response()->json($this->inventory->createSaleOrder($validated), 201);
    }

    public function confirmSaleOrder(int $saleOrderId): JsonResponse
    {
        return response()->json($this->inventory->confirmSaleOrder($saleOrderId));
    }

    public function reserveSaleOrder(int $saleOrderId): JsonResponse
    {
        return response()->json($this->inventory->reserveStock($saleOrderId));
    }

    public function fulfillSaleOrder(Request $request, int $saleOrderId): JsonResponse
    {
        $validated = $request->validate([
            'fulfilled_qtys'   => ['nullable', 'array'],
            'fulfilled_qtys.*' => ['numeric', 'gt:0'],
        ]);

        return response()->json($this->inventory->fulfillSaleOrder($saleOrderId, $validated['fulfilled_qtys'] ?? []));
    }

    public function cancelSaleOrder(int $saleOrderId): JsonResponse
    {
        return response()->json($this->inventory->cancelSaleOrder($saleOrderId));
    }

    public function ecommerceCheckout(Request $request): JsonResponse
    {
        return response()->json($this->checkoutFromCart($request, 'ecommerce'), 201);
    }

    public function posCheckout(Request $request): JsonResponse
    {
        return response()->json($this->checkoutFromCart($request, 'pos'), 201);
    }

    public function createTransfer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_warehouse_id'    => ['required', 'integer'],
            'to_warehouse_id'      => ['required', 'integer', 'different:from_warehouse_id'],
            'shipping_rate_per_kg' => ['nullable', 'numeric', 'min:0'],
            'notes'                => ['nullable', 'string'],
            'created_by'           => ['nullable', 'integer'],
            'items'                => ['required', 'array', 'min:1'],
            'items.*.product_id'   => ['required', 'integer'],
            'items.*.qty_sent'     => ['required', 'numeric', 'gt:0'],
        ]);

        return response()->json($this->inventory->createTransfer($validated), 201);
    }

    public function dispatchTransfer(int $transferId): JsonResponse
    {
        return response()->json($this->inventory->dispatchTransfer($transferId));
    }

    public function receiveTransfer(Request $request, int $transferId): JsonResponse
    {
        $validated = $request->validate([
            'received_qtys'   => ['nullable', 'array'],
            'received_qtys.*' => ['numeric', 'gt:0'],
        ]);

        return response()->json($this->inventory->receiveTransfer($transferId, $validated['received_qtys'] ?? []));
    }

    public function createAdjustment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id'       => ['required', 'integer'],
            'reason'             => ['required', 'string'],
            'adjusted_at'        => ['nullable', 'date'],
            'notes'              => ['nullable', 'string'],
            'created_by'         => ['nullable', 'integer'],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.qty_actual' => ['required', 'numeric'],
            'items.*.notes'      => ['nullable', 'string'],
        ]);

        return response()->json($this->inventory->createAdjustment($validated), 201);
    }

    public function postAdjustment(int $adjustmentId): JsonResponse
    {
        return response()->json($this->inventory->postAdjustment($adjustmentId));
    }

    private function checkoutFromCart(Request $request, string $channel): mixed
    {
        $validated = $request->validate([
            'cart_instance'   => ['nullable', 'string', 'max:100'],
            'warehouse_id'    => ['required', 'integer'],
            'customer_id'     => ['nullable', 'integer'],
            'price_tier_code' => ['nullable', 'string'],
            'currency'        => ['nullable', 'string', 'size:3'],
            'exchange_rate'   => ['nullable', 'numeric', 'gt:0'],
            'tax_local'       => ['nullable', 'numeric'],
            'discount_local'  => ['nullable', 'numeric'],
            'notes'           => ['nullable', 'string'],
            'created_by'      => ['nullable', 'integer'],
            'confirm'         => ['nullable', 'boolean'],
            'reserve'         => ['nullable', 'boolean'],
            'fulfill'         => ['nullable', 'boolean'],
            'clear_cart'      => ['nullable', 'boolean'],
            'source'          => ['nullable', 'string', 'max:100'],
            'context'         => ['nullable', 'array'],
        ]);

        return $this->cartCheckoutService->checkout($validated, $channel);
    }
}

<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Controllers\Api\{EntityCrudController, InventoryWorkflowController, PartnerApiController};
use Centrex\Inventory\Support\InventoryEntityRegistry;
use Illuminate\Support\Facades\Route;

Route::middleware(config('inventory.api_middleware', ['api', 'auth:sanctum']))
    ->prefix(config('inventory.api_prefix', 'api/inventory'))
    ->as('inventory.api.')
    ->group(function (): void {
        foreach (InventoryEntityRegistry::masterDataEntities() as $entity) {
            Route::get("/{$entity}", [EntityCrudController::class, 'index'])->defaults('entity', $entity)->name("{$entity}.index");
            Route::post("/{$entity}", [EntityCrudController::class, 'store'])->defaults('entity', $entity)->name("{$entity}.store");
            Route::get("/{$entity}/{recordId}", [EntityCrudController::class, 'show'])->defaults('entity', $entity)->name("{$entity}.show");
            Route::match(['put', 'patch'], "/{$entity}/{recordId}", [EntityCrudController::class, 'update'])->defaults('entity', $entity)->name("{$entity}.update");
            Route::delete("/{$entity}/{recordId}", [EntityCrudController::class, 'destroy'])->defaults('entity', $entity)->name("{$entity}.destroy");
        }

        Route::post('/exchange-rates/set', [InventoryWorkflowController::class, 'setExchangeRate'])->name('exchange-rates.set');
        Route::get('/exchange-rates/convert-to-bdt', [InventoryWorkflowController::class, 'convertToBdt'])->name('exchange-rates.convert-to-bdt');
        Route::get('/exchange-rates/convert-from-bdt', [InventoryWorkflowController::class, 'convertFromBdt'])->name('exchange-rates.convert-from-bdt');

        Route::post('/pricing', [InventoryWorkflowController::class, 'setPrice'])->name('pricing.set');
        Route::get('/pricing/resolve', [InventoryWorkflowController::class, 'resolvePrice'])->name('pricing.resolve');
        Route::get('/pricing/sheet', [InventoryWorkflowController::class, 'priceSheet'])->name('pricing.sheet');

        Route::get('/products/barcode', [InventoryWorkflowController::class, 'findByBarcode'])->name('products.barcode');
        Route::get('/reports/stock-levels', [InventoryWorkflowController::class, 'stockLevels'])->name('reports.stock-levels');
        Route::get('/reports/stock-valuation', [InventoryWorkflowController::class, 'stockValuation'])->name('reports.stock-valuation');
        Route::get('/reports/movement-history', [InventoryWorkflowController::class, 'movementHistory'])->name('reports.movement-history');

        Route::post('/purchase-orders', [InventoryWorkflowController::class, 'createPurchaseOrder'])->name('purchase-orders.store');
        Route::post('/purchase-orders/{purchaseOrderId}/submit', [InventoryWorkflowController::class, 'submitPurchaseOrder'])->name('purchase-orders.submit');
        Route::post('/purchase-orders/{purchaseOrderId}/confirm', [InventoryWorkflowController::class, 'confirmPurchaseOrder'])->name('purchase-orders.confirm');
        Route::post('/purchase-orders/{purchaseOrderId}/receipts', [InventoryWorkflowController::class, 'createStockReceipt'])->name('purchase-orders.receipts.store');

        Route::post('/stock-receipts/{stockReceiptId}/post', [InventoryWorkflowController::class, 'postStockReceipt'])->name('stock-receipts.post');
        Route::post('/stock-receipts/{stockReceiptId}/void', [InventoryWorkflowController::class, 'voidStockReceipt'])->name('stock-receipts.void');

        Route::post('/sale-orders', [InventoryWorkflowController::class, 'createSaleOrder'])->name('sale-orders.store');
        Route::post('/sale-orders/{saleOrderId}/confirm', [InventoryWorkflowController::class, 'confirmSaleOrder'])->name('sale-orders.confirm');
        Route::post('/sale-orders/{saleOrderId}/reserve', [InventoryWorkflowController::class, 'reserveSaleOrder'])->name('sale-orders.reserve');
        Route::post('/sale-orders/{saleOrderId}/fulfill', [InventoryWorkflowController::class, 'fulfillSaleOrder'])->name('sale-orders.fulfill');
        Route::post('/sale-orders/{saleOrderId}/cancel', [InventoryWorkflowController::class, 'cancelSaleOrder'])->name('sale-orders.cancel');
        Route::post('/channels/ecommerce/checkout', [InventoryWorkflowController::class, 'ecommerceCheckout'])->name('channels.ecommerce.checkout');
        Route::post('/channels/pos/checkout', [InventoryWorkflowController::class, 'posCheckout'])->name('channels.pos.checkout');

        Route::post('/transfers', [InventoryWorkflowController::class, 'createTransfer'])->name('transfers.store');
        Route::post('/transfers/{transferId}/dispatch', [InventoryWorkflowController::class, 'dispatchTransfer'])->name('transfers.dispatch');
        Route::post('/transfers/{transferId}/receive', [InventoryWorkflowController::class, 'receiveTransfer'])->name('transfers.receive');

        Route::post('/adjustments', [InventoryWorkflowController::class, 'createAdjustment'])->name('adjustments.store');
        Route::post('/adjustments/{adjustmentId}/post', [InventoryWorkflowController::class, 'postAdjustment'])->name('adjustments.post');

        Route::get('/partners', [InventoryWorkflowController::class, 'listPartners'])->name('partners.index');
        Route::post('/partners', [InventoryWorkflowController::class, 'createPartner'])->name('partners.store');
        Route::put('/partners/{partnerId}', [InventoryWorkflowController::class, 'updatePartner'])->name('partners.update');
        Route::post('/partners/{partnerId}/rotate-key', [InventoryWorkflowController::class, 'rotatePartnerApiKey'])->name('partners.rotate-key');

        Route::post('/pick-lists', [InventoryWorkflowController::class, 'createPickList'])->name('pick-lists.store');
        Route::post('/pick-lists/{pickListId}/start', [InventoryWorkflowController::class, 'startPicking'])->name('pick-lists.start');
        Route::post('/pick-lists/{pickListId}/confirm', [InventoryWorkflowController::class, 'confirmPick'])->name('pick-lists.confirm');

        Route::post('/shipments', [InventoryWorkflowController::class, 'createShipment'])->name('shipments.store');
        Route::post('/shipments/{shipmentId}/dispatch', [InventoryWorkflowController::class, 'dispatchShipment'])->name('shipments.dispatch');
        Route::post('/shipments/{shipmentId}/delivered', [InventoryWorkflowController::class, 'markShipmentDelivered'])->name('shipments.delivered');
    });

// Partner API — authenticated by X-Partner-Key header, no session/sanctum required
Route::middleware(config('inventory.partner_api_middleware', ['api']))
    ->prefix(config('inventory.api_prefix', 'api/inventory') . '/partner')
    ->as('inventory.partner.')
    ->group(function (): void {
        Route::get('/stock',          [PartnerApiController::class, 'stockLevels'])->name('stock');
        Route::get('/prices',         [PartnerApiController::class, 'priceSheet'])->name('prices');
        Route::post('/orders',        [PartnerApiController::class, 'createOrder'])->name('orders.store');
        Route::get('/orders/{soId}',  [PartnerApiController::class, 'getOrder'])->name('orders.show');
    });

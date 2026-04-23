<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Controllers\Web\{AsyncSelectController, DashboardController};
use Centrex\Inventory\Http\Livewire\Entities\{EntityFormPage, EntityIndexPage};
use Centrex\Inventory\Http\Livewire\Transactions\{AdjustmentFormPage, InventoryReportsPage, PosTerminalPage, PurchaseOrderFormPage, PurchaseOrderIndexPage, PurchaseOrderShowPage, PurchaseReturnFormPage, PurchaseReturnIndexPage, PurchaseReturnShowPage, SaleOrderFormPage, SaleOrderIndexPage, SaleOrderShowPage, SaleReturnFormPage, SaleReturnIndexPage, SaleReturnShowPage, TransferFormPage, TransferIndexPage, TransferShowPage};
use Centrex\Inventory\Support\InventoryEntityRegistry;
use Illuminate\Support\Facades\Route;

Route::middleware(config('inventory.web_middleware', ['web', 'auth']))
    ->prefix(config('inventory.web_prefix', 'inventory'))
    ->as('inventory.')
    ->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('/async-select/{resource}', AsyncSelectController::class)->name('async-select');

        foreach (InventoryEntityRegistry::masterDataEntities() as $entity) {
            Route::get("/{$entity}", EntityIndexPage::class)->name("entities.{$entity}.index")->defaults('entity', $entity);
            Route::get("/{$entity}/create", EntityFormPage::class)->name("entities.{$entity}.create")->defaults('entity', $entity);
            Route::get("/{$entity}/{recordId}/edit", EntityFormPage::class)->name("entities.{$entity}.edit")->defaults('entity', $entity);
        }

        Route::get('/purchase-orders', PurchaseOrderIndexPage::class)->name('purchase-orders.index');
        Route::get('/purchase-orders/create', PurchaseOrderFormPage::class)->name('purchase-orders.create');
        Route::get('/purchase-orders/{recordId}', PurchaseOrderShowPage::class)->name('purchase-orders.show');
        Route::get('/purchase-orders/{recordId}/edit', PurchaseOrderFormPage::class)->name('purchase-orders.edit');
        Route::get('/requisitions', PurchaseOrderIndexPage::class)->name('requisitions.index')->defaults('documentType', 'requisition');
        Route::get('/requisitions/create', PurchaseOrderFormPage::class)->name('requisitions.create')->defaults('documentType', 'requisition');
        Route::get('/requisitions/{recordId}', PurchaseOrderShowPage::class)->name('requisitions.show')->defaults('documentType', 'requisition');
        Route::get('/requisitions/{recordId}/edit', PurchaseOrderFormPage::class)->name('requisitions.edit')->defaults('documentType', 'requisition');
        Route::get('/sale-orders', SaleOrderIndexPage::class)->name('sale-orders.index');
        Route::get('/sale-orders/create', SaleOrderFormPage::class)->name('sale-orders.create');
        Route::get('/sale-orders/{recordId}', SaleOrderShowPage::class)->name('sale-orders.show');
        Route::get('/sale-orders/{recordId}/edit', SaleOrderFormPage::class)->name('sale-orders.edit');
        Route::get('/quotations', SaleOrderIndexPage::class)->name('quotations.index')->defaults('documentType', 'quotation');
        Route::get('/quotations/create', SaleOrderFormPage::class)->name('quotations.create')->defaults('documentType', 'quotation');
        Route::get('/quotations/{recordId}', SaleOrderShowPage::class)->name('quotations.show')->defaults('documentType', 'quotation');
        Route::get('/quotations/{recordId}/edit', SaleOrderFormPage::class)->name('quotations.edit')->defaults('documentType', 'quotation');
        Route::get('/sale-returns', SaleReturnIndexPage::class)->name('sale-returns.index');
        Route::get('/sale-returns/create', SaleReturnFormPage::class)->name('sale-returns.create');
        Route::get('/sale-returns/{recordId}', SaleReturnShowPage::class)->name('sale-returns.show');
        Route::get('/purchase-returns', PurchaseReturnIndexPage::class)->name('purchase-returns.index');
        Route::get('/purchase-returns/create', PurchaseReturnFormPage::class)->name('purchase-returns.create');
        Route::get('/purchase-returns/{recordId}', PurchaseReturnShowPage::class)->name('purchase-returns.show');
        Route::get('/pos', PosTerminalPage::class)->name('pos.index');
        Route::get('/transfers', TransferIndexPage::class)->name('transfers.index');
        Route::get('/transfers/create', TransferFormPage::class)->name('transfers.create');
        Route::get('/transfers/{recordId}', TransferShowPage::class)->name('transfers.show');
        Route::get('/adjustments/create', AdjustmentFormPage::class)->name('adjustments.create');
        Route::get('/reports', InventoryReportsPage::class)->name('reports.index');
    });

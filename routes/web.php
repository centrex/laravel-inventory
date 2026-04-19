<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Controllers\Web\DashboardController;
use Centrex\Inventory\Http\Livewire\Entities\{EntityFormPage, EntityIndexPage};
use Centrex\Inventory\Http\Livewire\Transactions\{AdjustmentFormPage, PosTerminalPage, PurchaseOrderFormPage, PurchaseOrderIndexPage, PurchaseOrderShowPage, SaleOrderFormPage, SaleOrderIndexPage, SaleOrderShowPage, TransferFormPage};
use Centrex\Inventory\Support\InventoryEntityRegistry;
use Illuminate\Support\Facades\Route;

Route::middleware(config('inventory.web_middleware', ['web', 'auth']))
    ->prefix(config('inventory.web_prefix', 'inventory'))
    ->as('inventory.')
    ->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');

        foreach (InventoryEntityRegistry::masterDataEntities() as $entity) {
            Route::get("/{$entity}", EntityIndexPage::class)->name("entities.{$entity}.index")->defaults('entity', $entity);
            Route::get("/{$entity}/create", EntityFormPage::class)->name("entities.{$entity}.create")->defaults('entity', $entity);
            Route::get("/{$entity}/{recordId}/edit", EntityFormPage::class)->name("entities.{$entity}.edit")->defaults('entity', $entity);
        }

        Route::get('/purchase-orders', PurchaseOrderIndexPage::class)->name('purchase-orders.index');
        Route::get('/purchase-orders/create', PurchaseOrderFormPage::class)->name('purchase-orders.create');
        Route::get('/purchase-orders/{recordId}', PurchaseOrderShowPage::class)->name('purchase-orders.show');
        Route::get('/purchase-orders/{recordId}/edit', PurchaseOrderFormPage::class)->name('purchase-orders.edit');
        Route::get('/sale-orders', SaleOrderIndexPage::class)->name('sale-orders.index');
        Route::get('/sale-orders/create', SaleOrderFormPage::class)->name('sale-orders.create');
        Route::get('/sale-orders/{recordId}', SaleOrderShowPage::class)->name('sale-orders.show');
        Route::get('/sale-orders/{recordId}/edit', SaleOrderFormPage::class)->name('sale-orders.edit');
        Route::get('/pos', PosTerminalPage::class)->name('pos.index');
        Route::get('/transfers/create', TransferFormPage::class)->name('transfers.create');
        Route::get('/adjustments/create', AdjustmentFormPage::class)->name('adjustments.create');
    });

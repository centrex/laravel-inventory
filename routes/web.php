<?php

declare(strict_types = 1);

use Centrex\Inventory\Http\Controllers\Web\DashboardController;
use Centrex\Inventory\Http\Livewire\Entities\{EntityFormPage, EntityIndexPage};
use Centrex\Inventory\Http\Livewire\Expenses\ExpensesPage;
use Centrex\Inventory\Http\Livewire\Payroll\PayrollEntriesPage;
use Centrex\Inventory\Http\Livewire\Transactions\{AdjustmentFormPage, PosTerminalPage, PurchaseOrderFormPage, SaleOrderFormPage, TransferFormPage};
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

        Route::get('/purchase-orders/create', PurchaseOrderFormPage::class)->name('purchase-orders.create');
        Route::get('/sale-orders/create', SaleOrderFormPage::class)->name('sale-orders.create');
        Route::get('/pos', PosTerminalPage::class)->name('pos.index');
        Route::get('/transfers/create', TransferFormPage::class)->name('transfers.create');
        Route::get('/adjustments/create', AdjustmentFormPage::class)->name('adjustments.create');
        Route::get('/expenses', ExpensesPage::class)->name('expenses.index');
        Route::get('/payroll', PayrollEntriesPage::class)->name('payroll.index');
    });

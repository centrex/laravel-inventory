<?php

declare(strict_types = 1);

namespace Centrex\Inventory;

use Centrex\Inventory\Models\{Customer, Expense, ExpenseItem, Supplier};
use Centrex\Inventory\Observers\{CustomerObserver, ExpenseItemObserver, ExpenseObserver, SupplierObserver};
use Centrex\Inventory\Support\{CartCheckoutService, ErpIntegration};
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'inventory');

        if ((bool) config('inventory.web_enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        }

        if ((bool) config('inventory.api_enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        }

        $this->registerLivewireComponents();
        Customer::observe(CustomerObserver::class);
        Supplier::observe(SupplierObserver::class);
        Expense::observe(ExpenseObserver::class);
        ExpenseItem::observe(ExpenseItemObserver::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/config.php' => config_path('inventory.php'),
            ], 'inventory-config');

            $this->publishes([
                __DIR__ . '/../database/migrations/' => database_path('migrations'),
            ], 'inventory-migrations');
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/config.php', 'inventory');

        $this->app->singleton('inventory', fn () => new Inventory());
        $this->app->singleton(ErpIntegration::class, fn () => new ErpIntegration());
        $this->app->singleton(CartCheckoutService::class, fn ($app) => new CartCheckoutService(
            $app->make(Inventory::class),
            $app->make(ErpIntegration::class),
        ));
    }

    private function registerLivewireComponents(): void
    {
        if (!class_exists(Livewire::class)) {
            return;
        }

        Livewire::component('inventory-entity-index', Http\Livewire\Entities\EntityIndexPage::class);
        Livewire::component('inventory-entity-form', Http\Livewire\Entities\EntityFormPage::class);
        Livewire::component('inventory-sale-order-form', Http\Livewire\Transactions\SaleOrderFormPage::class);
        Livewire::component('inventory-purchase-order-form', Http\Livewire\Transactions\PurchaseOrderFormPage::class);
        Livewire::component('inventory-transfer-form', Http\Livewire\Transactions\TransferFormPage::class);
        Livewire::component('inventory-adjustment-form', Http\Livewire\Transactions\AdjustmentFormPage::class);
        Livewire::component('inventory-pos-terminal', Http\Livewire\Transactions\PosTerminalPage::class);
        Livewire::component('inventory-expenses', Http\Livewire\Expenses\ExpensesPage::class);
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory;

use Centrex\Inventory\Models\{Customer, Supplier};
use Centrex\Inventory\Observers\{CustomerObserver, SupplierObserver};
use Centrex\Inventory\Support\ErpIntegration;
use Illuminate\Support\Facades\{Blade, Gate};
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'inventory');
        $this->registerViteDirective();

        if ((bool) config('inventory.web_enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        }

        if ((bool) config('inventory.api_enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        }

        $this->app->booted(fn (): mixed => $this->registerLivewireComponents());
        $this->registerGates();

        if ((bool) config('inventory.erp.accounting.enabled', false)) {
            Customer::observe(CustomerObserver::class);
            Supplier::observe(SupplierObserver::class);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/config.php' => config_path('inventory.php'),
            ], 'inventory-config');

            $this->publishes([
                __DIR__ . '/../database/migrations/' => database_path('migrations'),
            ], 'inventory-migrations');
        }
    }

    /**
     * Register default authorization gates for inventory actions.
     *
     * Each gate falls back to the `inventory-admin` super-gate, so host apps
     * can grant blanket access by defining that single gate, or override
     * individual abilities for fine-grained control.
     *
     * Default behaviour: denies everyone (safe default).
     * Override in AppServiceProvider after calling parent::boot().
     */
    protected function registerGates(): void
    {
        $abilities = [
            // Master data (warehouses, products, suppliers, customers, etc.)
            'inventory.master-data.view',
            'inventory.master-data.manage',

            // Exchange rates
            'inventory.exchange-rates.view',
            'inventory.exchange-rates.manage',

            // Pricing
            'inventory.pricing.view',
            'inventory.pricing.manage',

            // Reports
            'inventory.reports.view',
            'inventory.logistics.view',
            'inventory.commercial-teams.manage',

            // Purchase orders
            'inventory.purchase-orders.view',
            'inventory.purchase-orders.create',
            'inventory.purchase-orders.submit',
            'inventory.purchase-orders.confirm',

            // Stock receipts (GRN)
            'inventory.stock-receipts.create',
            'inventory.stock-receipts.post',
            'inventory.stock-receipts.void',

            // Sale orders
            'inventory.sale-orders.view',
            'inventory.sale-orders.create',
            'inventory.sale-orders.approve-credit',
            'inventory.sale-orders.confirm',
            'inventory.sale-orders.reserve',
            'inventory.sale-orders.fulfill',
            'inventory.sale-orders.cancel',

            // Channels (POS / ecommerce checkout)
            'inventory.channels.checkout',

            // Transfers
            'inventory.transfers.view',
            'inventory.transfers.create',
            'inventory.transfers.dispatch',
            'inventory.transfers.receive',

            // Adjustments
            'inventory.adjustments.view',
            'inventory.adjustments.create',
            'inventory.adjustments.post',
        ];

        foreach ($abilities as $ability) {
            if (!Gate::has($ability)) {
                Gate::define($ability, static function ($user) use ($ability): bool {
                    if (Gate::has('inventory-admin') && Gate::forUser($user)->check('inventory-admin')) {
                        return true;
                    }

                    if (method_exists($user, 'allTeams') && method_exists($user, 'hasTeamPermission')) {
                        try {
                            if ($user->allTeams()->contains(
                                fn ($team): bool => $user->hasTeamPermission($team, $ability),
                            )) {
                                return true;
                            }
                        } catch (\Throwable) {
                            // Fall through to role-based fallback.
                        }
                    }

                    $roleAttribute = config('inventory.admin_role_attribute');

                    if ($roleAttribute && method_exists($user, 'hasRole')) {
                        return $user->hasRole($this->normalizeAdminRoles(config('inventory.admin_roles', [])));
                    }

                    if (method_exists($user, 'hasRole')) {
                        return $user->hasRole($this->normalizeAdminRoles(config('inventory.admin_roles', [])));
                    }

                    return false;
                });
            }
        }
    }

    private function normalizeAdminRoles(array|string|null $roles): array
    {
        if (is_array($roles)) {
            return array_values(array_filter(array_map('strval', $roles)));
        }

        if (!is_string($roles) || trim($roles) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $roles))));
    }

    private function registerViteDirective(): void
    {
        Blade::directive('inventoryVite', fn (): string => sprintf(
            '<?php echo \\Centrex\\TallUi\\Support\\PackageVite::render(%s, %s, %s); ?>',
            var_export(dirname(__DIR__), true),
            var_export('inventory.hot', true),
            var_export(['resources/js/app.js'], true),
        ));
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/config.php', 'inventory');

        $this->app->singleton('inventory', fn () => new Inventory());
        $this->app->singleton(ErpIntegration::class, fn () => new ErpIntegration());
    }

    private function registerLivewireComponents(): void
    {
        if (!class_exists(Livewire::class) || !$this->app->bound('livewire.finder')) {
            return;
        }

        Livewire::component('inventory-entity-index', Http\Livewire\Entities\EntityIndexPage::class);
        Livewire::component('inventory-entity-form', Http\Livewire\Entities\EntityFormPage::class);
        Livewire::component('inventory-sale-order-index', Http\Livewire\Transactions\SaleOrderIndexPage::class);
        Livewire::component('inventory-sale-order-show', Http\Livewire\Transactions\SaleOrderShowPage::class);
        Livewire::component('inventory-sale-order-form', Http\Livewire\Transactions\SaleOrderFormPage::class);
        Livewire::component('inventory-purchase-order-index', Http\Livewire\Transactions\PurchaseOrderIndexPage::class);
        Livewire::component('inventory-purchase-order-show', Http\Livewire\Transactions\PurchaseOrderShowPage::class);
        Livewire::component('inventory-purchase-order-form', Http\Livewire\Transactions\PurchaseOrderFormPage::class);
        Livewire::component('inventory-sale-return-index', Http\Livewire\Transactions\SaleReturnIndexPage::class);
        Livewire::component('inventory-sale-return-form', Http\Livewire\Transactions\SaleReturnFormPage::class);
        Livewire::component('inventory-sale-return-show', Http\Livewire\Transactions\SaleReturnShowPage::class);
        Livewire::component('inventory-purchase-return-index', Http\Livewire\Transactions\PurchaseReturnIndexPage::class);
        Livewire::component('inventory-purchase-return-form', Http\Livewire\Transactions\PurchaseReturnFormPage::class);
        Livewire::component('inventory-purchase-return-show', Http\Livewire\Transactions\PurchaseReturnShowPage::class);
        Livewire::component('inventory-transfer-index', Http\Livewire\Transactions\TransferIndexPage::class);
        Livewire::component('inventory-transfer-form', Http\Livewire\Transactions\TransferFormPage::class);
        Livewire::component('inventory-transfer-show', Http\Livewire\Transactions\TransferShowPage::class);
        Livewire::component('inventory-reports-page', Http\Livewire\Transactions\InventoryReportsPage::class);
        Livewire::component('inventory-adjustment-form', Http\Livewire\Transactions\AdjustmentFormPage::class);
        Livewire::component('inventory-pos-terminal', Http\Livewire\Transactions\PosTerminalPage::class);
    }
}

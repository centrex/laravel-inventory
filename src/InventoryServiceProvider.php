<?php

declare(strict_types = 1);

namespace Centrex\Inventory;

use Centrex\Inventory\Commands\{InventoryCommand, SnapshotTrendsCommand, SyncExchangeRatesCommand};
use Centrex\Inventory\Models\{Customer, Supplier};
use Centrex\Inventory\Observers\{CustomerObserver, InvoicePaymentObserver, SupplierObserver};
use Centrex\Inventory\Support\{AccountingInventorySnapshotProvider, ErpIntegration};
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

        $this->app->booted(function (): void {
            $this->registerLivewireComponents();
        });
        $this->registerGates();

        if ((bool) config('inventory.erp.accounting.enabled', false)) {
            Customer::observe(CustomerObserver::class);
            Supplier::observe(SupplierObserver::class);

            if (class_exists(\Centrex\Accounting\Models\Invoice::class)) {
                \Centrex\Accounting\Models\Invoice::observe(InvoicePaymentObserver::class);
            }
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/config.php' => config_path('inventory.php'),
            ], 'inventory-config');

            $this->publishes([
                __DIR__ . '/../database/migrations/' => database_path('migrations'),
            ], 'inventory-migrations');

            $this->commands([
                InventoryCommand::class,
                SnapshotTrendsCommand::class,
                SyncExchangeRatesCommand::class,
                Commands\FitCustomerClvCommand::class,
                Commands\VoidCancelledOrderInvoicesCommand::class,
            ]);

            $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
                $schedule->command('inventory:sync-exchange-rates')
                    ->dailyAt('00:30')
                    ->withoutOverlapping()
                    ->runInBackground()
                    ->appendOutputTo(storage_path('logs/exchange-rates-sync.log'));
            });
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
            'inventory.purchase-orders.view-all', // bypass commercial-team scope; see every PO
            'inventory.purchase-orders.create',
            'inventory.purchase-orders.edit',
            'inventory.purchase-orders.submit',
            'inventory.purchase-orders.confirm',
            'inventory.purchase-orders.receive',
            'inventory.purchase-orders.cancel',

            // Stock receipts (GRN)
            'inventory.stock-receipts.create',
            'inventory.stock-receipts.post',
            'inventory.stock-receipts.void',

            // Sale orders
            // Note: 'inventory.sale-orders.view-all' is defined separately below (it also
            // checks inventory.sale_orders_view_all_roles, not just inventory.admin_roles).
            'inventory.sale-orders.view',
            'inventory.sale-orders.create',
            'inventory.sale-orders.edit',
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

            // Partners (manage partner records — admin only)
            'inventory.partners.view',
            'inventory.partners.manage',

            // Pick-Pack-Ship
            'inventory.pick-lists.view',
            'inventory.shipments.view',
        ];

        // Partner back-office gates: granted to users with a partner role.
        // These gates scope access to the partner's own customer data only.
        $partnerAbilities = [
            'inventory.partner.view-stock',
            'inventory.partner.view-prices',
            'inventory.partner.create-order',
            'inventory.partner.view-own-orders',
        ];

        foreach ($partnerAbilities as $ability) {
            if (!Gate::has($ability)) {
                Gate::define($ability, function ($user) {
                    $partnerRoles = $this->normalizeAdminRoles(config('inventory.partner_roles', 'dropshipper,ecom-partner'));

                    if (method_exists($user, 'hasRole') && !empty($partnerRoles)) {
                        return $user->hasRole($partnerRoles);
                    }

                    return false;
                });
            }
        }

        // Sale order field-level permission gates — fall back to admin super-gate.
        // Configure allowed roles via INVENTORY_PRICE_OVERRIDE_ROLES / INVENTORY_DISCOUNT_ROLES.
        $saleOrderFieldGates = [
            'inventory.sale-orders.override-price' => 'price_override_roles',
            'inventory.sale-orders.apply-discount' => 'discount_roles',
        ];

        foreach ($saleOrderFieldGates as $ability => $configKey) {
            if (!Gate::has($ability)) {
                Gate::define($ability, function ($user) use ($configKey): bool {
                    if (Gate::has('inventory-admin') && Gate::forUser($user)->check('inventory-admin')) {
                        return true;
                    }

                    if (method_exists($user, 'hasRole')) {
                        $roles = $this->normalizeAdminRoles(config("inventory.{$configKey}", []));

                        return !empty($roles) && $user->hasRole($roles);
                    }

                    return false;
                });
            }
        }

        // Dispatch terminal tab gates — fall back to admin super-gate.
        $terminalTabGates = [
            'inventory.dispatch.dispatcher-tab' => 'dispatcher_roles',
            'inventory.dispatch.updater-tab'    => 'updater_roles',
        ];

        foreach ($terminalTabGates as $ability => $configKey) {
            if (!Gate::has($ability)) {
                Gate::define($ability, function ($user) use ($configKey): bool {
                    if (Gate::has('inventory-admin') && Gate::forUser($user)->check('inventory-admin')) {
                        return true;
                    }

                    if (method_exists($user, 'hasRole')) {
                        $roles = $this->normalizeAdminRoles(config("inventory.{$configKey}", []));

                        return !empty($roles) && $user->hasRole($roles);
                    }

                    return false;
                });
            }
        }

        // Sale-order "view all" bypass for CommercialTeamAccess scoping. Unlike the generic
        // abilities below (which only auto-grant via inventory.admin_roles or a Jetstream Team
        // permission), this also checks inventory.sale_orders_view_all_roles — so a role like
        // 'dispatcher' can see every sale order without being a full inventory admin.
        if (!Gate::has('inventory.sale-orders.view-all')) {
            Gate::define('inventory.sale-orders.view-all', function ($user): bool {
                if (Gate::has('inventory-admin') && Gate::forUser($user)->check('inventory-admin')) {
                    return true;
                }

                if (method_exists($user, 'allTeams') && method_exists($user, 'hasTeamPermission')) {
                    try {
                        if ($user->allTeams()->contains(
                            fn ($team): bool => $user->hasTeamPermission($team, 'inventory.sale-orders.view-all'),
                        )) {
                            return true;
                        }
                    } catch (\Throwable) {
                        // Fall through to role-based fallback.
                    }
                }

                if (method_exists($user, 'hasRole')) {
                    $roles = array_values(array_unique([
                        ...$this->normalizeAdminRoles(config('inventory.admin_roles', [])),
                        ...$this->normalizeAdminRoles(config('inventory.sale_orders_view_all_roles', '')),
                    ]));

                    return $roles !== [] && $user->hasRole($roles);
                }

                return false;
            });
        }

        // High-value sale order confirmation — deliberately a separate role list from
        // sale_order_high_value_confirm_roles rather than the generic inventory.admin_roles,
        // since not every day-to-day admin should necessarily be trusted to confirm large orders.
        if (!Gate::has('inventory.sale-orders.confirm-high-value')) {
            Gate::define('inventory.sale-orders.confirm-high-value', function ($user): bool {
                if (Gate::has('inventory-admin') && Gate::forUser($user)->check('inventory-admin')) {
                    return true;
                }

                if (method_exists($user, 'allTeams') && method_exists($user, 'hasTeamPermission')) {
                    try {
                        if ($user->allTeams()->contains(
                            fn ($team): bool => $user->hasTeamPermission($team, 'inventory.sale-orders.confirm-high-value'),
                        )) {
                            return true;
                        }
                    } catch (\Throwable) {
                        // Fall through to role-based fallback.
                    }
                }

                if (method_exists($user, 'hasRole')) {
                    $roles = $this->normalizeAdminRoles(config('inventory.sale_order_high_value_confirm_roles', 'general_manager,system_administrator'));

                    return $roles !== [] && $user->hasRole($roles);
                }

                return false;
            });
        }

        foreach ($abilities as $ability) {
            if (!Gate::has($ability)) {
                Gate::define($ability, function ($user) use ($ability): bool {
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

        if (interface_exists(\Centrex\Accounting\Contracts\InventorySnapshotProvider::class)) {
            $this->app->bind(
                \Centrex\Accounting\Contracts\InventorySnapshotProvider::class,
                AccountingInventorySnapshotProvider::class,
            );
        }
    }

    private function registerLivewireComponents(): void
    {
        if (!class_exists(Livewire::class) || !$this->app->bound('livewire.finder')) {
            return;
        }

        Livewire::component('inventory-entity-index', Http\Livewire\Entities\EntityIndexPage::class);
        Livewire::component('inventory-entity-form', Http\Livewire\Entities\EntityFormPage::class);
        Livewire::component('inventory-product-index', Http\Livewire\Entities\ProductIndexPage::class);
        Livewire::component('inventory-product-table', Http\Livewire\Entities\ProductTable::class);
        Livewire::component('inventory-customer-show', Http\Livewire\Entities\CustomerShowPage::class);
        Livewire::component('inventory-customer-index', Http\Livewire\Entities\CustomerIndexPage::class);
        Livewire::component('inventory-customer-table', Http\Livewire\Entities\CustomerTable::class);
        Livewire::component('inventory-supplier-index', Http\Livewire\Entities\SupplierIndexPage::class);
        Livewire::component('inventory-supplier-table', Http\Livewire\Entities\SupplierTable::class);
        Livewire::component('inventory-manage-addresses', Http\Livewire\Entities\ManageAddresses::class);
        Livewire::component('inventory-warehouse-stock-index', Http\Livewire\Entities\WarehouseStockIndexPage::class);
        Livewire::component('inventory-warehouse-stock-table', Http\Livewire\Entities\WarehouseStockTable::class);
        Livewire::component('inventory-product-price-table', Http\Livewire\Entities\ProductPriceTable::class);
        Livewire::component('inventory-sale-order-index', Http\Livewire\Transactions\SaleOrderIndexPage::class);
        Livewire::component('inventory-sale-order-table', Http\Livewire\Transactions\SaleOrderTable::class);
        Livewire::component('inventory-sale-order-show', Http\Livewire\Transactions\SaleOrderShowPage::class);
        Livewire::component('inventory-sale-order-form', Http\Livewire\Transactions\SaleOrderFormPage::class);
        Livewire::component('inventory-purchase-order-index', Http\Livewire\Transactions\PurchaseOrderIndexPage::class);
        Livewire::component('inventory-purchase-order-table', Http\Livewire\Transactions\PurchaseOrderTable::class);
        Livewire::component('inventory-purchase-order-show', Http\Livewire\Transactions\PurchaseOrderShowPage::class);
        Livewire::component('inventory-purchase-order-form', Http\Livewire\Transactions\PurchaseOrderFormPage::class);
        Livewire::component('inventory-sale-return-index', Http\Livewire\Transactions\SaleReturnIndexPage::class);
        Livewire::component('inventory-sale-return-table', Http\Livewire\Transactions\SaleReturnTable::class);
        Livewire::component('inventory-sale-return-form', Http\Livewire\Transactions\SaleReturnFormPage::class);
        Livewire::component('inventory-sale-return-show', Http\Livewire\Transactions\SaleReturnShowPage::class);
        Livewire::component('inventory-purchase-return-index', Http\Livewire\Transactions\PurchaseReturnIndexPage::class);
        Livewire::component('inventory-purchase-return-table', Http\Livewire\Transactions\PurchaseReturnTable::class);
        Livewire::component('inventory-purchase-return-form', Http\Livewire\Transactions\PurchaseReturnFormPage::class);
        Livewire::component('inventory-purchase-return-show', Http\Livewire\Transactions\PurchaseReturnShowPage::class);
        Livewire::component('inventory-transfer-index', Http\Livewire\Transactions\TransferIndexPage::class);
        Livewire::component('inventory-transfer-form', Http\Livewire\Transactions\TransferFormPage::class);
        Livewire::component('inventory-transfer-show', Http\Livewire\Transactions\TransferShowPage::class);
        Livewire::component('inventory-shipment-index', Http\Livewire\Transactions\ShipmentIndexPage::class);
        Livewire::component('inventory-shipment-show', Http\Livewire\Transactions\ShipmentShowPage::class);
        Livewire::component('inventory-reports-page', Http\Livewire\Transactions\InventoryReportsPage::class);
        Livewire::component('inventory-sales-report', Http\Livewire\Transactions\SalesReportPage::class);
        Livewire::component('inventory-purchase-report', Http\Livewire\Transactions\PurchaseReportPage::class);
        Livewire::component('inventory-stock-report', Http\Livewire\Transactions\StockReportPage::class);
        Livewire::component('inventory-forecast-report', Http\Livewire\Transactions\ForecastReportPage::class);
        Livewire::component('inventory-customer-heatmap', Http\Livewire\Transactions\CustomerHeatMapPage::class);
        Livewire::component('inventory-adjustment-form', Http\Livewire\Transactions\AdjustmentFormPage::class);
        Livewire::component('inventory-pos-terminal', Http\Livewire\Transactions\PosTerminalPage::class);
        Livewire::component('inventory-dispatch-terminal', Http\Livewire\Transactions\DispatchTerminalPage::class);
    }
}

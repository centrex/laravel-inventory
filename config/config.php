<?php

declare(strict_types = 1);

return [

    /*
    |--------------------------------------------------------------------------
    | Base Currency
    |--------------------------------------------------------------------------
    | All financial amounts are stored in this currency (BDT).
    */
    'base_currency' => env('INVENTORY_BASE_CURRENCY', 'BDT'),

    /*
    |--------------------------------------------------------------------------
    | Purchase Defaults
    |--------------------------------------------------------------------------
    | Default warehouse (matched by name) and currency for purchase orders.
    | The exchange rate is always resolved from the exchange rate table.
    */
    'purchase_defaults' => [
        'warehouse_name' => env('INVENTORY_PURCHASE_DEFAULT_WAREHOUSE', 'UK'),
        'currency'       => env('INVENTORY_PURCHASE_DEFAULT_CURRENCY', 'GBP'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sale Defaults
    |--------------------------------------------------------------------------
    | Default warehouse (matched by name) and currency for sale orders.
    | The exchange rate is always resolved from the exchange rate table.
    */
    'sale_defaults' => [
        'warehouse_name' => env('INVENTORY_SALE_DEFAULT_WAREHOUSE', 'UK'),
        'currency'       => env('INVENTORY_SALE_DEFAULT_CURRENCY', 'GBP'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Driver
    |--------------------------------------------------------------------------
    */
    'drivers' => [
        'database' => [
            'connection' => env('INVENTORY_DB_CONNECTION'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Prefix
    |--------------------------------------------------------------------------
    */
    'table_prefix' => env('INVENTORY_TABLE_PREFIX', 'inv_'),

    /*
    |--------------------------------------------------------------------------
    | Web Routes
    |--------------------------------------------------------------------------
    */
    'web_middleware' => ['web', 'auth'],
    'web_prefix'     => 'inventory',
    'web_enabled'    => env('INVENTORY_WEB_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | API Routes
    |--------------------------------------------------------------------------
    */
    'api_middleware' => ['api', 'auth:sanctum'],
    'api_prefix'     => 'api/inventory',
    'api_enabled'    => env('INVENTORY_API_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | WAC Precision
    |--------------------------------------------------------------------------
    | Decimal places for weighted average cost storage.
    */
    'wac_precision' => env('INVENTORY_WAC_PRECISION', 4),

    /*
    |--------------------------------------------------------------------------
    | Exchange Rate Staleness Threshold
    |--------------------------------------------------------------------------
    | Days before a rate is considered stale in getExchangeRate() fallback.
    */
    'exchange_rate_stale_days' => env('INVENTORY_EXCHANGE_RATE_STALE_DAYS', 1),

    /*
    |--------------------------------------------------------------------------
    | Live Exchange Rate Fetch
    |--------------------------------------------------------------------------
    | When getExchangeRate() finds no usable rate in oer_exchange_rates, fetch it live from the
    | Open Exchange Rates API (requires OPEN_EXCHANGE_RATES_APP_ID) and persist it for future
    | lookups. Disable if you'd rather rely solely on `inventory:sync-exchange-rates` and fail
    | fast when a rate is missing.
    */
    'exchange_rate_live_fetch' => env('INVENTORY_EXCHANGE_RATE_LIVE_FETCH', true),

    /*
    |--------------------------------------------------------------------------
    | High-Value Sale Order Confirmation
    |--------------------------------------------------------------------------
    | Sale orders whose total_amount (base currency) is at or above this threshold require the
    | confirming user to hold inventory.sale-orders.confirm-high-value, in addition to the
    | ordinary inventory.sale-orders.confirm ability. Set to 0 to disable (any order confirmable
    | per the ordinary confirm ability alone). Role fallback is only used when the host app has
    | no Jurager/team-permission grant for the ability — see InventoryServiceProvider::registerGates().
    */
    'sale_order_high_value_threshold' => env('INVENTORY_SALE_ORDER_HIGH_VALUE_THRESHOLD', 0),
    'sale_order_high_value_confirm_roles' => env('INVENTORY_SALE_ORDER_HIGH_VALUE_CONFIRM_ROLES', 'general_manager,system_administrator'),

    /*
    |--------------------------------------------------------------------------
    | Price Resolution
    |--------------------------------------------------------------------------
    */
    'price_not_found_throws' => env('INVENTORY_PRICE_NOT_FOUND_THROWS', true),

    /*
    |--------------------------------------------------------------------------
    | Quantity Tolerance
    |--------------------------------------------------------------------------
    | Tolerance for floating-point quantity comparisons.
    */
    'qty_tolerance' => env('INVENTORY_QTY_TOLERANCE', 0.0001),

    /*
    |--------------------------------------------------------------------------
    | Default Shipping Rate
    |--------------------------------------------------------------------------
    | Default rate per kg in BDT for inter-warehouse transfers.
    */
    'default_shipping_rate_per_kg' => env('INVENTORY_DEFAULT_SHIPPING_RATE_KG', 0),

    /*
    |--------------------------------------------------------------------------
    | Auto Seed Price Tiers (deprecated)
    | Retained for backward compatibility. Price tiers are enum-backed and
    | no longer stored in a dedicated database table.
    |--------------------------------------------------------------------------
    */
    'seed_price_tiers' => env('INVENTORY_SEED_PRICE_TIERS', true),

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
    'admin_roles'          => env('INVENTORY_ADMIN_ROLES', 'administrator,admin,superadmin,inventory-manager'),
    'admin_role_attribute' => env('INVENTORY_ADMIN_ROLE_ATTRIBUTE', null),
    'user_foreign_keys'    => env('INVENTORY_USER_FOREIGN_KEYS', false),

    // Roles that grant back-office partner access (view own orders, create partner orders)
    'partner_roles' => env('INVENTORY_PARTNER_ROLES', 'dropshipper,ecom-partner'),

    // Roles that can see the Dispatcher tab on the dispatch terminal
    'dispatcher_roles' => env('INVENTORY_DISPATCHER_ROLES', 'dispatcher'),

    // Roles that can see the Sale Updater tab on the dispatch terminal
    'updater_roles' => env('INVENTORY_UPDATER_ROLES', 'sales-manager,logistics-manager'),

    // Roles that bypass CommercialTeamAccess scoping on the regular Sale Orders list/show/edit
    // pages and see every sale order, regardless of team assignment (inventory.admin_roles
    // always bypasses too — this is for roles that need the same reach without being full
    // inventory admins, e.g. dispatchers who need to look up any order, not just their own).
    'sale_orders_view_all_roles' => env('INVENTORY_SALE_ORDERS_VIEW_ALL_ROLES', ''),

    // Roles allowed to override the system-resolved unit price on a sale order line.
    // Admins (inventory.admin_roles) always bypass this check.
    'price_override_roles' => env('INVENTORY_PRICE_OVERRIDE_ROLES', ''),

    // Roles allowed to apply a line-level discount % or an order-level discount amount.
    // Admins (inventory.admin_roles) always bypass this check.
    'discount_roles' => env('INVENTORY_DISCOUNT_ROLES', ''),
    // Middleware for the partner API key endpoints (no session required — uses X-Partner-Key header)
    'partner_api_middleware' => ['api'],

    /*
    |--------------------------------------------------------------------------
    | ERP Integration
    |--------------------------------------------------------------------------
    */
    'erp' => [
        'accounting' => [
            'enabled'  => env('INVENTORY_ACCOUNTING_ENABLED', true),
            'accounts' => [
                'inventory_asset'      => env('INVENTORY_ACCOUNTING_INVENTORY_ASSET', '1300'),
                'cost_of_goods_sold'   => env('INVENTORY_ACCOUNTING_COGS', '5000'),
                // Must match the accounting package's ACCOUNTING_ACCOUNT_GRNI — this GRN posting's
                // credit and the matching bill's debit-clear (see Accounting::postBill()) both need
                // to land on the same account so they net to zero once the bill is posted.
                'goods_received_clear' => env('INVENTORY_ACCOUNTING_GRNI', '2050'),
                'inventory_gain'       => env('INVENTORY_ACCOUNTING_GAIN', '4900'),
                'inventory_loss'       => env('INVENTORY_ACCOUNTING_LOSS', '5000'),
                'accounts_receivable'  => env('INVENTORY_ACCOUNTING_AR', '1200'),
                'accounts_payable'     => env('INVENTORY_ACCOUNTING_AP', '2000'),
                'sales_returns'        => env('INVENTORY_ACCOUNTING_SALES_RETURNS', '6134'),
                'purchase_returns'     => env('INVENTORY_ACCOUNTING_PURCHASE_RETURNS', '5504'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */
    'per_page' => [
        'products'        => 50,
        'warehouses'      => 20,
        'purchase_orders' => 15,
        'sale_orders'     => 15,
        'transfers'       => 15,
        'stock_movements' => 25,
        'adjustments'     => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Number Format
    |--------------------------------------------------------------------------
    */
    'number_format' => [
        'decimals'      => 2,
        'decimal_point' => '.',
        'thousands_sep' => ',',
    ],

];

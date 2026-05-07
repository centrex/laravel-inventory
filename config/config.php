<?php

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
    'partner_roles'           => env('INVENTORY_PARTNER_ROLES', 'dropshipper,ecom-partner'),

    // Roles that can see the Dispatcher tab on the dispatch terminal
    'dispatcher_roles'        => env('INVENTORY_DISPATCHER_ROLES', 'dispatcher'),

    // Roles that can see the Sale Updater tab on the dispatch terminal
    'updater_roles'           => env('INVENTORY_UPDATER_ROLES', 'sales-manager,logistics-manager'),
    // Middleware for the partner API key endpoints (no session required — uses X-Partner-Key header)
    'partner_api_middleware'  => ['api'],

    /*
    |--------------------------------------------------------------------------
    | ERP Integration
    |--------------------------------------------------------------------------
    */
    'erp' => [
        'accounting' => [
            'enabled' => env('INVENTORY_ACCOUNTING_ENABLED', true),
            'accounts' => [
                'inventory_asset'      => env('INVENTORY_ACCOUNTING_INVENTORY_ASSET', '1300'),
                'cost_of_goods_sold'   => env('INVENTORY_ACCOUNTING_COGS', '5000'),
                'goods_received_clear' => env('INVENTORY_ACCOUNTING_GRNI', '2000'),
                'inventory_gain'       => env('INVENTORY_ACCOUNTING_GAIN', '4900'),
                'inventory_loss'       => env('INVENTORY_ACCOUNTING_LOSS', '5000'),
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

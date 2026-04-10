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

    /*
    |--------------------------------------------------------------------------
    | API Routes
    |--------------------------------------------------------------------------
    */
    'api_middleware' => ['api', 'auth:sanctum'],
    'api_prefix'     => 'api/inventory',

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
    'default_shipping_rate_per_kg_bdt' => env('INVENTORY_DEFAULT_SHIPPING_RATE_KG', 0),

    /*
    |--------------------------------------------------------------------------
    | Auto Seed Price Tiers
    |--------------------------------------------------------------------------
    */
    'seed_price_tiers' => env('INVENTORY_SEED_PRICE_TIERS', true),

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */
    'admin_roles'          => env('INVENTORY_ADMIN_ROLES', 'inventory-manager,admin'),
    'admin_role_attribute' => env('INVENTORY_ADMIN_ROLE_ATTRIBUTE', null),

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

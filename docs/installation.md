# Installation

## Requirements

- PHP 8.2+
- Laravel 11+
- Livewire 3 (for the web UI)

## Install the package

```bash
composer require centrex/laravel-inventory
```

## Publish config and migrate

```bash
php artisan vendor:publish --tag="inventory-config"
php artisan migrate
```

## Publish views (optional — to customise templates)

```bash
php artisan vendor:publish --tag="inventory-views"
```

---

## Configuration

`config/inventory.php` (published above):

```php
return [
    'base_currency'              => env('INVENTORY_BASE_CURRENCY', 'BDT'),
    'drivers'                    => ['database' => ['connection' => env('INVENTORY_DB_CONNECTION')]],
    'table_prefix'               => env('INVENTORY_TABLE_PREFIX', 'inv_'),
    'web_middleware'             => ['web', 'auth'],
    'web_prefix'                 => 'inventory',
    'web_enabled'                => env('INVENTORY_WEB_ENABLED', true),
    'api_middleware'             => ['api', 'auth:sanctum'],
    'api_prefix'                 => 'api/inventory',
    'api_enabled'                => env('INVENTORY_API_ENABLED', true),
    'wac_precision'              => env('INVENTORY_WAC_PRECISION', 4),
    'exchange_rate_stale_days'   => env('INVENTORY_EXCHANGE_RATE_STALE_DAYS', 1),
    'price_not_found_throws'     => env('INVENTORY_PRICE_NOT_FOUND_THROWS', true),
    'qty_tolerance'              => env('INVENTORY_QTY_TOLERANCE', 0.0001),
    'default_shipping_rate_per_kg' => env('INVENTORY_DEFAULT_SHIPPING_RATE_KG', 0),
    'admin_roles'                => env('INVENTORY_ADMIN_ROLES', 'administrator,admin,superadmin,inventory-manager'),
    'erp' => [
        'accounting' => [
            'enabled'   => env('INVENTORY_ACCOUNTING_ENABLED', true),
            'accounts'  => [
                'inventory_asset'      => env('INVENTORY_ACCOUNTING_INVENTORY_ASSET', '1300'),
                'cost_of_goods_sold'   => env('INVENTORY_ACCOUNTING_COGS', '5000'),
                'goods_received_clear' => env('INVENTORY_ACCOUNTING_GRNI', '2000'),
                'inventory_gain'       => env('INVENTORY_ACCOUNTING_GAIN', '4900'),
                'inventory_loss'       => env('INVENTORY_ACCOUNTING_LOSS', '5000'),
            ],
        ],
    ],
    'per_page' => [
        'products'        => 50,
        'warehouses'      => 20,
        'purchase_orders' => 15,
        'sale_orders'     => 15,
        'transfers'       => 15,
        'stock_movements' => 25,
        'adjustments'     => 15,
    ],
];
```

## Environment variables

```env
INVENTORY_BASE_CURRENCY=BDT
INVENTORY_DB_CONNECTION=             # optional dedicated DB connection
INVENTORY_TABLE_PREFIX=inv_
INVENTORY_WAC_PRECISION=4
INVENTORY_EXCHANGE_RATE_STALE_DAYS=1
INVENTORY_PRICE_NOT_FOUND_THROWS=true
INVENTORY_QTY_TOLERANCE=0.0001
INVENTORY_DEFAULT_SHIPPING_RATE_KG=0
INVENTORY_WEB_ENABLED=true
INVENTORY_API_ENABLED=true
INVENTORY_ADMIN_ROLES=administrator,admin,superadmin,inventory-manager
INVENTORY_ACCOUNTING_ENABLED=true
INVENTORY_ACCOUNTING_INVENTORY_ASSET=1300
INVENTORY_ACCOUNTING_COGS=5000
INVENTORY_ACCOUNTING_GRNI=2000
INVENTORY_ACCOUNTING_GAIN=4900
INVENTORY_ACCOUNTING_LOSS=5000
```

## ERP integration with laravel-accounting

When `INVENTORY_ACCOUNTING_ENABLED=true`, the following events automatically post journal entries:

| Inventory event | Journal entry created |
| --- | --- |
| GRN posted | DR Inventory (1300) / CR GRN Clearing (2000) |
| Sale fulfilled | DR COGS (5000) / CR Inventory (1300) |
| Adjustment posted | DR/CR Inventory (1300) vs Gain (4900) or Loss (5000) |
| GRN voided | Reverses the original GRN entry |

Set `INVENTORY_ACCOUNTING_ENABLED=false` to run the inventory module standalone without accounting.

# Exchange Rates

Exchange rates convert foreign-currency document amounts into the base currency (BDT). A rate is locked onto each document at creation time — changing a rate later does not affect existing documents.

## Setting rates

```php
use Centrex\Inventory\Facades\Inventory;

// Manual rate — 1 CNY = 16.50 BDT on 2026-04-10
Inventory::setExchangeRate('CNY', 16.50, date: '2026-04-10', source: 'manual');

// Defaults to today if date is omitted
Inventory::setExchangeRate('USD', 110.00);

// Auto-fetched from laravel-open-exchange-rates (if configured)
Inventory::setExchangeRate('EUR', null, source: 'api');
```

## Reading rates

```php
$rate = Inventory::getExchangeRate('CNY', date: '2026-04-10'); // 16.50

// Returns 1.0 if currency matches base currency (BDT)
$rate = Inventory::getExchangeRate('BDT'); // 1.0
```

The `INVENTORY_EXCHANGE_RATE_STALE_DAYS` config controls how many days old a rate can be before a warning is issued.

## Converting amounts

```php
// Any currency → BDT
$bdt = Inventory::convertToBase(100.00, 'CNY');   // 1,650.0000
$bdt = Inventory::convertToBdt(100.00, 'CNY');    // alias

// BDT → any currency
$cny = Inventory::convertFromBase(1650.00, 'CNY'); // 100.0000
$cny = Inventory::convertFromBdt(1650.00, 'CNY'); // alias
```

Results are rounded to `INVENTORY_WAC_PRECISION` decimal places (default 4).

## How rates are applied to documents

When creating a purchase order or sale order in a foreign currency, pass the exchange rate explicitly or let the system fetch the latest stored rate:

```php
// Explicit rate — locked at creation
$po = Inventory::createPurchaseOrder([
    'currency'      => 'CNY',
    'exchange_rate' => 16.50,
    'items'         => [...],
]);

// Auto-fetched — uses most recent stored rate for CNY
$po = Inventory::createPurchaseOrder([
    'currency' => 'CNY',
    // exchange_rate omitted
    'items'    => [...],
]);
```

The rate is stored on the document and used for all amount conversions on that document.

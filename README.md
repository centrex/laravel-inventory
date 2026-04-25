# laravel-inventory

[![Latest Version on Packagist](https://img.shields.io/packagist/v/centrex/laravel-inventory.svg?style=flat-square)](https://packagist.org/packages/centrex/laravel-inventory)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/centrex/laravel-inventory/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/centrex/laravel-inventory/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/centrex/laravel-inventory/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/centrex/laravel-inventory/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/centrex/laravel-inventory?style=flat-square)](https://packagist.org/packages/centrex/laravel-inventory)

Full multi-warehouse inventory management for Laravel. Supports weighted average costing (WAC), multi-currency purchasing and selling, inter-warehouse transfers with per-kg shipping costs, seven configurable sell price tiers per product per warehouse, and optional ERP integration with `laravel-accounting` for automatic journal entries.

## Contents

- [Installation](#installation)
- [Configuration](#configuration)
- [Core Concepts](#core-concepts)
- [Exchange Rates](#exchange-rates)
- [Warehouses & Products](#warehouses--products)
- [Price Tiers & Pricing](#price-tiers--pricing)
- [Coupons](#coupons)
- [Customers & Suppliers](#customers--suppliers)
- [Purchase Orders & GRNs](#purchase-orders--grns)
- [Sale Orders](#sale-orders)
- [Returns](#returns)
- [Inter-Warehouse Transfers](#inter-warehouse-transfers)
- [Stock Adjustments](#stock-adjustments)
- [Stock Queries & Reports](#stock-queries--reports)
- [Sales Forecast](#sales-forecast)
- [Authorization Gates](#authorization-gates)
- [Web UI](#web-ui)
- [REST API](#rest-api)
- [Exceptions](#exceptions)
- [Environment Variables](#environment-variables)
- [Testing](#testing)
- [Changelog](#changelog)
- [License](#license)

---

## Installation

```bash
composer require centrex/laravel-inventory
```

Publish the config and run migrations:

```bash
php artisan vendor:publish --tag="inventory-config"
php artisan migrate
```

Seed the default price tiers (`base`, `wholesale`, `retail`, `dropshipping`, `fcom`):

```php
use Centrex\Inventory\Facades\Inventory;

Inventory::seedPriceTiers();
```

---

## Configuration

```php
// config/inventory.php

return [
    'base_currency'                    => env('INVENTORY_BASE_CURRENCY', 'BDT'),
    'drivers'                          => ['database' => ['connection' => env('INVENTORY_DB_CONNECTION')]],
    'table_prefix'                     => env('INVENTORY_TABLE_PREFIX', 'inv_'),
    'wac_precision'                    => 4,
    'exchange_rate_stale_days'         => 1,
    'price_not_found_throws'           => true,
    'qty_tolerance'                    => 0.0001,
    'default_shipping_rate_per_kg' => 0,
    'seed_price_tiers'                 => true,
];
```

---

## Core Concepts

| Concept | Description |
|---|---|
| **Base currency** | BDT. Every financial amount is stored in BDT. Foreign-currency amounts are stored alongside with their exchange rate locked at the document level. |
| **WAC** | Weighted average cost per product per warehouse. Recalculated on every receipt and transfer receipt using a `SELECT FOR UPDATE` lock to prevent race conditions. |
| **Price tiers** | Five tiers: `base`, `wholesale`, `retail`, `dropshipping`, `fcom`. Prices can be set globally or overridden per warehouse. Warehouse-specific price wins. |
| **Transfers** | Moving stock between warehouses adds a per-kg shipping cost (allocated pro-rata across items by weight) to the landed cost at the destination, which feeds into the destination's WAC. |
| **Stock movements** | Append-only audit log. Every quantity change writes an immutable row. Voids write compensating rows — nothing is deleted or updated. |

---

## Exchange Rates

Set exchange rates before creating any multi-currency documents.

```php
use Centrex\Inventory\Facades\Inventory;

// 1 CNY = 16.50 BDT on 2026-04-10
Inventory::setExchangeRate('CNY', 16.50, '2026-04-10');

// 1 USD = 110.00 BDT (defaults to today)
Inventory::setExchangeRate('USD', 110.00);

// Get the rate for a currency on a specific date
$rate = Inventory::getExchangeRate('CNY', '2026-04-10'); // 16.50

// Convert between currencies
$bdt = Inventory::convertToBdt(100, 'CNY');   // 1650.0000 BDT
$usd = Inventory::convertFromBdt(1100, 'USD'); // 10.0000 USD
```

---

## Warehouses & Products

```php
use Centrex\Inventory\Models\{Warehouse, Product, ProductCategory};

// Create warehouses
$wh_dhaka = Warehouse::create([
    'code'         => 'WH-BD-01',
    'name'         => 'Dhaka Warehouse',
    'country_code' => 'BD',
    'currency'     => 'BDT',  // native purchase/sale currency
    'is_default'   => true,
]);

$wh_china = Warehouse::create([
    'code'         => 'WH-CN-01',
    'name'         => 'Guangzhou Warehouse',
    'country_code' => 'CN',
    'currency'     => 'CNY',
]);

$wh_us = Warehouse::create([
    'code'         => 'WH-US-01',
    'name'         => 'New York Warehouse',
    'country_code' => 'US',
    'currency'     => 'USD',
]);

// Create a product with weight (required for transfer shipping cost calculation)
$category = ProductCategory::create(['name' => 'Electronics', 'slug' => 'electronics']);

$product = Product::create([
    'category_id'  => $category->id,
    'sku'          => 'PHONE-X1',
    'name'         => 'Smartphone X1',
    'unit'         => 'pcs',
    'weight_kg'    => 0.350,  // 350g per unit
    'is_stockable' => true,
]);
```

---

## Price Tiers & Pricing

### Set prices

```php
use Centrex\Inventory\Facades\Inventory;
use Centrex\Inventory\Enums\PriceTierCode;

// Global prices (apply to all warehouses unless overridden)
Inventory::setPrice($product->id, PriceTierCode::BASE->value,         5500.00);
Inventory::setPrice($product->id, PriceTierCode::WHOLESALE->value,    5200.00);
Inventory::setPrice($product->id, PriceTierCode::RETAIL->value,       6500.00);
Inventory::setPrice($product->id, PriceTierCode::DROPSHIPPING->value, 6200.00);
Inventory::setPrice($product->id, PriceTierCode::FCOM->value,         6800.00);

// Warehouse-specific override (China warehouse sells cheaper due to lower cost base)
Inventory::setPrice($product->id, PriceTierCode::WHOLESALE->value, 4800.00, $wh_china->id);
Inventory::setPrice($product->id, PriceTierCode::RETAIL->value,    5900.00, $wh_china->id);

// With local currency reference (informational — BDT is the source of truth)
Inventory::setPrice($product->id, PriceTierCode::RETAIL->value, 6500.00, $wh_dhaka->id, [
    'price_local' => 6500.00,
    'currency'    => 'BDT',
]);

// Time-limited price
Inventory::setPrice($product->id, PriceTierCode::RETAIL->value, 5500.00, null, [
    'effective_from' => '2026-06-01',
    'effective_to'   => '2026-06-30',
]);
```

### Resolve & read prices

```php
// Resolve the effective retail price at Dhaka warehouse
// (warehouse-specific wins over global; falls back to global if no warehouse price)
$price = Inventory::resolvePrice($product->id, 'retail', $wh_dhaka->id);
echo $price->price_amount; // 6500.00

// Get the full price sheet for a product at a warehouse (all tiers)
$sheet = Inventory::getPriceSheet($product->id, $wh_china->id);
// Returns a Collection:
// [
//   ['tier_code' => 'base',         'price_amount' => 5500.00, 'source' => 'global'],
//   ['tier_code' => 'wholesale',    'price_amount' => 4800.00, 'source' => 'warehouse'],
//   ['tier_code' => 'retail',       'price_amount' => 5900.00, 'source' => 'warehouse'],
//   ['tier_code' => 'dropshipping', 'price_amount' => 6200.00, 'source' => 'global'],
//   ['tier_code' => 'fcom',         'price_amount' => 6800.00, 'source' => 'global'],
// ]
```

---

## Coupons

Coupons apply at the sale-order level and are stored as order snapshots so historic orders do not change if the coupon is later edited.

- Coupon amounts are stored in the inventory base currency.
- `fixed` coupons are converted into the order currency at checkout time.
- `percent` coupons use the order subtotal before tax and before manual order-level discount.
- Manual `discount_local` and coupon discounts can both be used on the same order.

### Create coupons

Coupons can be managed from the Inventory master-data UI under `Coupons`, or created directly:

```php
use Centrex\Inventory\Models\Coupon;

Coupon::create([
    'code'                    => 'SAVE10',
    'name'                    => 'Save 10%',
    'discount_type'           => 'percent', // percent|fixed
    'discount_value'          => 10,
    'minimum_subtotal_amount' => 1000,      // base currency amount
    'maximum_discount_amount' => 500,       // optional cap, base currency amount
    'usage_limit'             => 100,
    'starts_at'               => now(),
    'ends_at'                 => now()->addMonth(),
    'is_active'               => true,
]);
```

### Apply coupons to sale orders

```php
$saleOrder = Inventory::createSaleOrder([
    'warehouse_id'    => $warehouse->id,
    'customer_id'     => $customer->id,
    'currency'        => 'BDT',
    'price_tier_code' => 'b2c_retail',
    'coupon_code'     => 'SAVE10',
    'items'           => [[
        'product_id'       => $product->id,
        'qty_ordered'      => 2,
        'unit_price_local' => 500,
    ]],
]);

echo $saleOrder->coupon_discount_local; // 100.00
echo $saleOrder->total_local;           // 900.00
```

The sale-order form and POS terminal also accept coupon codes.

---

## Purchase Orders & GRNs

### Create a purchase order

Each PO targets a single warehouse and a single supplier currency. The exchange rate is locked at creation time.

```php
use Centrex\Inventory\Facades\Inventory;
use Centrex\Inventory\Models\Supplier;

$supplier = Supplier::create([
    'code'     => 'SUP-CN-001',
    'name'     => 'Shenzhen Electronics Co.',
    'currency' => 'CNY',
]);

// Purchase in CNY for the China warehouse
$po = Inventory::createPurchaseOrder([
    'warehouse_id'      => $wh_china->id,
    'supplier_id'       => $supplier->id,
    'currency'          => 'CNY',
    'exchange_rate' => 16.50,  // optional: auto-fetched from exchange_rates if omitted
    'tax_local'         => 100.00,
    'shipping_local'    => 50.00,
    'notes'             => 'Spring restock order',
    'items'             => [
        [
            'product_id'      => $product->id,
            'qty_ordered'     => 200,
            'unit_price_local' => 180.00,  // CNY per unit = 2,970 BDT at 16.50
        ],
    ],
]);

// $po->po_number  => "PO-20260410-0001"
// $po->total_amount  => (200 × 180 × 16.50) + tax_amount + shipping_amount

// Move through statuses
Inventory::submitPurchaseOrder($po->id);   // draft → submitted
Inventory::confirmPurchaseOrder($po->id);  // submitted → confirmed
```

### Receive stock (GRN)

```php
// Create a draft GRN against the PO
$grn = Inventory::createStockReceipt($po->id, [
    [
        'purchase_order_item_id' => $po->items->first()->id,
        'qty_received'           => 200,
        // unit_cost_local defaults to PO unit_price_local if omitted
    ],
]);

// Post the GRN: increments inv_warehouse_products.qty_on_hand,
// recalculates WAC, writes a stock_movement row
$grn = Inventory::postStockReceipt($grn->id);

// Check new stock level
$stock = Inventory::getStockLevel($product->id, $wh_china->id);
echo $stock->qty_on_hand; // 200
echo $stock->wac_amount;     // 2970.0000 (180 CNY × 16.50)

// Void a posted GRN (writes compensating movement — never deletes)
Inventory::voidStockReceipt($grn->id);
```

---

## Sale Orders

### Create and fulfill a sale order

```php
use Centrex\Inventory\Facades\Inventory;
use Centrex\Inventory\Models\Customer;

$customer = Customer::create([
    'code'          => 'CUST-001',
    'name'          => 'Acme Traders',
    'currency'      => 'USD',
    'price_tier_id' => \Centrex\Inventory\Models\PriceTier::where('code', 'wholesale')->value('id'),
]);

// Sell in USD from the Dhaka warehouse at wholesale prices
$so = Inventory::createSaleOrder([
    'warehouse_id'      => $wh_dhaka->id,
    'customer_id'       => $customer->id,
    'price_tier_code'   => 'wholesale',
    'currency'          => 'USD',
    'exchange_rate' => 110.00,
    'items'             => [
        [
            'product_id'  => $product->id,
            'qty_ordered' => 50,
            // unit_price_local auto-resolved from inv_product_prices (wholesale tier, Dhaka warehouse)
            // override with 'unit_price_local' => 47.27 if needed
        ],
    ],
]);

// Confirm → reserve stock → fulfill
Inventory::confirmSaleOrder($so->id);
Inventory::reserveStock($so->id);         // increments qty_reserved, blocks overselling
Inventory::fulfillSaleOrder($so->id);     // decrements qty_on_hand, stamps COGS at WAC

$so->refresh();
echo $so->status->value;   // "fulfilled"
echo $so->cogs_amount;        // 50 × wac_amount at time of fulfillment
echo $so->grossMarginPct(); // gross margin %

// Partial fulfillment — supply a qty per line item
Inventory::fulfillSaleOrder($so->id, [
    $so->items->first()->id => 30,  // fulfill only 30 of 50 ordered
]);
// $so->status → "partial"

// Cancel (releases reserved qty automatically)
Inventory::cancelSaleOrder($so->id);
```

### Per-line price tier override

```php
$so = Inventory::createSaleOrder([
    'warehouse_id'    => $wh_dhaka->id,
    'price_tier_code' => 'retail',       // order-level default
    'currency'        => 'BDT',
    'exchange_rate' => 1.0,
    'items'           => [
        [
            'product_id'       => $product->id,
            'qty_ordered'      => 10,
            'price_tier_code'  => 'fcom',  // override tier for this line
            'discount_pct'     => 5,        // 5% line discount
        ],
    ],
]);
```

---

## Inter-Warehouse Transfers

Stock moved between warehouses carries a shipping cost per kg. This shipping cost is spread across items pro-rata by weight and added to the landed unit cost at the destination, which then feeds into the destination's WAC.

```php
use Centrex\Inventory\Facades\Inventory;

// Transfer 100 units from China → Dhaka at 15 BDT/kg shipping
$transfer = Inventory::createTransfer([
    'from_warehouse_id'        => $wh_china->id,
    'to_warehouse_id'          => $wh_dhaka->id,
    'shipping_rate_per_kg' => 15.00,
    'notes'                    => 'Monthly replenishment',
    'items'                    => [
        [
            'product_id' => $product->id,
            'qty_sent'   => 100,
            // weight_kg_total = 100 × 0.350 kg = 35 kg
        ],
    ],
]);

// $transfer->shipping_cost_amount          => 35 kg × 15 = 525.00 BDT
// $transfer->items[0]->shipping_allocated_amount => 525.00 (100% weight share)
// $transfer->items[0]->unit_landed_cost_amount   => source_wac + 525/100 = WAC + 5.25

// Dispatch: decrements China stock, increments China qty_in_transit
Inventory::dispatchTransfer($transfer->id);

// Receive at Dhaka: increments Dhaka stock, recalculates Dhaka WAC
// using unit_landed_cost_amount (source WAC + allocated shipping)
Inventory::receiveTransfer($transfer->id);

// Partial receipt — supply a qty per transfer item
Inventory::receiveTransfer($transfer->id, [
    $transfer->items->first()->id => 60,  // receive 60 of 100 sent
]);
// $transfer->status → "partial"
```

### Multi-product transfer

```php
$transfer = Inventory::createTransfer([
    'from_warehouse_id'        => $wh_us->id,
    'to_warehouse_id'          => $wh_dhaka->id,
    'shipping_rate_per_kg' => 80.00,  // air freight
    'items'                    => [
        ['product_id' => $productA->id, 'qty_sent' => 20],  // 2 kg each → 40 kg
        ['product_id' => $productB->id, 'qty_sent' => 50],  // 0.1 kg each → 5 kg
        // total weight: 45 kg, shipping: 3,600 BDT
        // productA gets: 40/45 × 3600 = 3,200 BDT shipping
        // productB gets:  5/45 × 3600 =   400 BDT shipping
    ],
]);
```

---

## Stock Adjustments

Use adjustments for cycle counts, write-offs, damage, theft, or expiry.

```php
use Centrex\Inventory\Facades\Inventory;
use Centrex\Inventory\Enums\AdjustmentReason;

// The system reads current qty_on_hand automatically — you only supply qty_actual
$adjustment = Inventory::createAdjustment([
    'warehouse_id' => $wh_dhaka->id,
    'reason'       => AdjustmentReason::CYCLE_COUNT->value,
    'notes'        => 'Monthly cycle count — bay 3',
    'items'        => [
        [
            'product_id' => $product->id,
            'qty_actual'  => 145,  // system shows 150, actual count is 145 → delta: -5
        ],
    ],
]);

// Post: applies qty_delta to warehouse stock, writes adjustment_out movement
Inventory::postAdjustment($adjustment->id);

// Write-off example
$adj = Inventory::createAdjustment([
    'warehouse_id' => $wh_dhaka->id,
    'reason'       => AdjustmentReason::DAMAGE->value,
    'items'        => [
        ['product_id' => $product->id, 'qty_actual' => 140],
    ],
]);
Inventory::postAdjustment($adj->id);
```

---

## Stock Queries & Reports

### Stock levels

```php
use Centrex\Inventory\Facades\Inventory;

// Single product at a warehouse
$stock = Inventory::getStockLevel($product->id, $wh_dhaka->id);
echo $stock->qty_on_hand;   // 145
echo $stock->qty_reserved;  // 50 (reserved by pending sale orders)
echo $stock->qty_in_transit; // 100 (dispatched transfer not yet received)
echo $stock->qtyAvailable(); // qty_on_hand - qty_reserved = 95
echo $stock->wac_amount;        // weighted average cost in BDT

// All products at a warehouse
$levels = Inventory::getStockLevels($wh_dhaka->id);

// Low stock alerts (all warehouses, or filter by one)
$lowStock = Inventory::getLowStockItems();
$lowStock = Inventory::getLowStockItems($wh_dhaka->id);

// Total stock value in BDT
$totalValue = Inventory::getStockValue();                // all warehouses
$warehouseValue = Inventory::getStockValue($wh_dhaka->id); // single warehouse
```

### Valuation report

```php
$report = Inventory::stockValuationReport($wh_dhaka->id);
// Returns a Collection of arrays:
// [
//   'warehouse'       => 'Dhaka Warehouse',
//   'sku'             => 'PHONE-X1',
//   'product'         => 'Smartphone X1',
//   'qty_on_hand'     => 145.0,
//   'qty_reserved'    => 50.0,
//   'qty_available'   => 95.0,
//   'wac_amount'         => 3105.2500,
//   'total_value_amount' => 450261.25,
// ]
```

### Movement history

```php
$movements = Inventory::getMovementHistory(
    productId:   $product->id,
    warehouseId: $wh_dhaka->id,
    from:        '2026-01-01',
    to:          '2026-04-30',
);

foreach ($movements as $m) {
    echo "[{$m->moved_at}] {$m->movement_type->label()} {$m->direction} {$m->qty} "
       . "| before: {$m->qty_before} → after: {$m->qty_after} "
       . "| WAC: {$m->wac_amount} BDT\n";
}
```

---

## Customers & Suppliers

### Customer management

```php
use Centrex\Inventory\Facades\Inventory;

// Create a customer
$customer = Inventory::createCustomer([
    'code'                => 'CUST-001',
    'name'                => 'Rahman Brothers Ltd',
    'email'               => 'accounts@rahman.com',
    'phone'               => '+880 1711-000000',
    'currency'            => 'BDT',
    'credit_limit_amount' => 500000,        // 0 = no limit
    'price_tier_code'     => 'b2b_wholesale',
    'is_active'           => true,
]);

Inventory::updateCustomer($customer->id, ['credit_limit_amount' => 750000]);
Inventory::deleteCustomer($customer->id);   // soft-delete

// Link customer to any Eloquent model (User, Contact, etc.)
$customer = Inventory::findCustomerForModel(App\Models\User::class, $userId);

// Credit limit snapshot — real-time exposure across open orders
$credit = Inventory::customerCreditSnapshot($customer->id);
// returns:
// [
//   'credit_limit_amount'   => 500000.0,
//   'outstanding_exposure'  => 123400.0,  // sum of open SO totals in base currency
//   'available_credit'      => 376600.0,
// ]

// Recent order history
$history = Inventory::customerHistory($customer->id, limit: 10);
```

### Credit limit enforcement

When a sale order is created and the customer would exceed their credit limit, the order is flagged and requires explicit override approval:

```php
$so = Inventory::createSaleOrder([
    'customer_id'     => $customer->id,
    'warehouse_id'    => $wh->id,
    'price_tier_code' => 'b2b_wholesale',
    'currency'        => 'BDT',
    'items'           => [...],
]);

if ($so->credit_override_required) {
    // $so->credit_exposure_before_amount — exposure before this order
    // $so->credit_exposure_after_amount  — exposure if approved
    // Approve via the gate inventory.sale-orders.approve-credit
}
```

### Supplier management

```php
use Centrex\Inventory\Models\Supplier;

$supplier = Supplier::create([
    'code'          => 'SUP-CN-001',
    'name'          => 'Shenzhen Electronics Co.',
    'country_code'  => 'CN',
    'currency'      => 'CNY',
    'contact_name'  => 'Li Wei',
    'contact_email' => 'liwei@szcorp.cn',
    'contact_phone' => '+86 ...',
    'is_active'     => true,
]);
```

---

## Returns

### Sale returns (customer returns)

```php
use Centrex\Inventory\Facades\Inventory;

$return = Inventory::createSaleReturn([
    'sale_order_id' => $so->id,
    'warehouse_id'  => $wh_dhaka->id,
    'customer_id'   => $customer->id,
    'returned_at'   => today(),
    'notes'         => 'Screen defect — customer complaint',
    'created_by'    => auth()->id(),
    'items' => [
        [
            'sale_order_item_id' => $so->items->first()->id,
            'qty_returned'       => 2,
            'unit_price_amount'  => 6500.00,  // selling price at time of sale
            'unit_cost_amount'   => 3105.25,  // WAC at time of fulfillment
        ],
    ],
]);

// Post: restores qty_on_hand (CUSTOMER_RETURN movement), reverses COGS JE in accounting
Inventory::postSaleReturn($return->id);
```

### Purchase returns (return to supplier)

```php
$return = Inventory::createPurchaseReturn([
    'purchase_order_id' => $po->id,
    'warehouse_id'      => $wh_dhaka->id,
    'supplier_id'       => $supplier->id,
    'returned_at'       => today(),
    'notes'             => 'Wrong SKU delivered',
    'created_by'        => auth()->id(),
    'items' => [
        [
            'purchase_order_item_id' => $po->items->first()->id,
            'qty_returned'           => 5,
            'unit_cost_amount'       => 2970.00,  // cost at receipt
        ],
    ],
]);

// Post: decrements qty_on_hand (RETURN_TO_SUPPLIER movement), recalculates WAC
Inventory::postPurchaseReturn($return->id);
```

---

## Sales Forecast

The forecast analyses historical sales patterns and projects future demand, procurement needs, and cash flow.

```php
use Centrex\Inventory\Facades\Inventory;

$forecast = Inventory::salesForecast(
    lookbackDays:  90,   // historical window to analyse
    forecastDays:  90,   // future projection window
    productLimit:  50,   // top N products by volume
    customerLimit: 25,   // top N customers by revenue
);

// $forecast['window']
// ['history_start', 'history_end', 'lookback_days', 'forecast_days']

// $forecast['summary']
// [
//   'products_tracked'          => 47,
//   'products_at_risk'          => 6,     // forecasted stockout within window
//   'forecast_qty'              => 2840,
//   'forecast_revenue'          => 18460000.0,
//   'required_procurement_cost' => 12200000.0,
//   'forecast_cash_in'          => 18460000.0,
//   'forecast_cash_out'         => 12200000.0,
//   'forecast_cash_net'         => 6260000.0,
// ]

// $forecast['products'] — per-product projection
foreach ($forecast['products'] as $p) {
    echo $p['product_name'];
    echo $p['history_qty'];         // units sold in lookback window
    echo $p['forecast_qty'];        // projected units in forecast window
    echo $p['avg_daily_qty'];       // average units/day
    echo $p['days_of_cover'];       // days until stockout at current rate
    echo $p['stockout_date'];       // projected date (null if not at risk)
    echo $p['pending_supply_qty'];  // qty already on confirmed POs
    echo $p['confidence'];          // high | medium | low
}

// $forecast['timeline'] — daily/weekly series for charts
// ['categories', 'series' => ['qty', 'revenue', 'cash_in', 'cash_out', 'net'], 'totals']
```

---

## Authorization Gates

All gates fall back to the `inventory-admin` super-gate. Configure via `INVENTORY_ADMIN_ROLES` or override in `AppServiceProvider`:

```php
Gate::define('inventory-admin', fn ($user) => $user->hasRole(['admin', 'inventory-manager']));

// Or override individual abilities
Gate::define('inventory.sale-orders.approve-credit', fn ($user) => $user->hasRole('finance-manager'));
Gate::define('inventory.adjustments.post',           fn ($user) => $user->hasRole('warehouse-manager'));
```

| Gate | Description |
|---|---|
| `inventory.master-data.view` | View warehouses, products, categories, brands, coupons |
| `inventory.master-data.manage` | Create, edit, delete master data records |
| `inventory.exchange-rates.view` | View exchange rates |
| `inventory.exchange-rates.manage` | Set exchange rates |
| `inventory.pricing.view` | View product prices |
| `inventory.pricing.manage` | Set and update product prices |
| `inventory.reports.view` | View stock valuation, movement history, forecast |
| `inventory.purchase-orders.view` | View purchase orders |
| `inventory.purchase-orders.create` | Create purchase orders |
| `inventory.purchase-orders.submit` | Submit PO for confirmation |
| `inventory.purchase-orders.confirm` | Confirm a submitted PO |
| `inventory.stock-receipts.create` | Create GRNs |
| `inventory.stock-receipts.post` | Post GRNs to inventory |
| `inventory.stock-receipts.void` | Void a posted GRN |
| `inventory.sale-orders.view` | View sale orders |
| `inventory.sale-orders.create` | Create sale orders |
| `inventory.sale-orders.confirm` | Confirm a sale order |
| `inventory.sale-orders.reserve` | Reserve stock for a sale order |
| `inventory.sale-orders.fulfill` | Fulfill a sale order |
| `inventory.sale-orders.cancel` | Cancel a sale order |
| `inventory.sale-orders.approve-credit` | Override customer credit limit |
| `inventory.channels.checkout` | POS terminal / e-commerce checkout |
| `inventory.transfers.view` | View transfers |
| `inventory.transfers.create` | Create transfers |
| `inventory.transfers.dispatch` | Dispatch a transfer |
| `inventory.transfers.receive` | Receive a transfer |
| `inventory.adjustments.view` | View adjustments |
| `inventory.adjustments.create` | Create adjustments |
| `inventory.adjustments.post` | Post adjustments to inventory |

---

## Web UI

The package ships a full Livewire UI enabled by default. Set `INVENTORY_WEB_ENABLED=false` to disable. All routes are under the `web_prefix` (default `inventory`), protected by `web_middleware` (default `['web', 'auth']`).

| URL | Description |
|---|---|
| `/inventory/dashboard` | Overview: stock values per warehouse, low-stock alerts |
| `/inventory/master-data/warehouses` | Warehouse management |
| `/inventory/master-data/products` | Product catalog |
| `/inventory/master-data/product-categories` | Category tree |
| `/inventory/master-data/product-brands` | Brand list |
| `/inventory/master-data/customers` | Customer management |
| `/inventory/master-data/suppliers` | Supplier management |
| `/inventory/master-data/coupons` | Coupon management |
| `/inventory/purchase-orders` | Purchase order list |
| `/inventory/purchase-orders/create` | New purchase order / requisition |
| `/inventory/purchase-orders/{id}` | PO detail: items, GRN history, status actions |
| `/inventory/sale-orders` | Sale order list |
| `/inventory/sale-orders/create` | New sale order / quotation |
| `/inventory/sale-orders/{id}` | SO detail: items, fulfillment, credit status |
| `/inventory/returns/sale` | Sale return list |
| `/inventory/returns/purchase` | Purchase return list |
| `/inventory/transfers` | Transfer list |
| `/inventory/transfers/create` | New transfer |
| `/inventory/transfers/{id}` | Transfer detail: boxes, items, receive actions |
| `/inventory/adjustments/create` | New stock adjustment |
| `/inventory/reports` | Valuation, movement history, forecast |
| `/inventory/pos` | Point-of-sale terminal |

---

## REST API

Set `INVENTORY_API_ENABLED=false` to disable. Base prefix: `api/inventory`. Default middleware: `['api', 'auth:sanctum']`.

### Exchange rates & pricing

| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/inventory/exchange-rates/set` | Set exchange rate |
| GET | `/api/inventory/exchange-rates/convert-to-bdt` | Convert amount to base currency |
| POST | `/api/inventory/pricing` | Set product price for a tier |
| GET | `/api/inventory/pricing/resolve` | Resolve effective price |
| GET | `/api/inventory/pricing/sheet` | Full price sheet for all tiers |

### Master data (CRUD)

All entities support: `GET /api/inventory/{entity}` (list), `POST` (create), `GET /{id}` (show), `PUT /{id}` (update), `DELETE /{id}` (delete).

Entities: `warehouses`, `product-categories`, `product-brands`, `products`, `suppliers`, `customers`, `coupons`, `product-prices`, `warehouse-products`.

### Purchase orders

| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/inventory/purchase-orders` | Create PO |
| POST | `/api/inventory/purchase-orders/{id}/submit` | Submit PO |
| POST | `/api/inventory/purchase-orders/{id}/confirm` | Confirm PO |
| POST | `/api/inventory/purchase-orders/{id}/receive` | Receive items (partial or full) |
| POST | `/api/inventory/purchase-orders/{id}/cancel` | Cancel PO |
| POST | `/api/inventory/stock-receipts` | Create GRN |
| POST | `/api/inventory/stock-receipts/{id}/post` | Post GRN |
| POST | `/api/inventory/stock-receipts/{id}/void` | Void GRN |

### Sale orders

| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/inventory/sale-orders` | Create SO |
| POST | `/api/inventory/sale-orders/{id}/confirm` | Confirm SO |
| POST | `/api/inventory/sale-orders/{id}/reserve` | Reserve stock |
| POST | `/api/inventory/sale-orders/{id}/fulfill` | Fulfill SO |
| POST | `/api/inventory/sale-orders/{id}/cancel` | Cancel SO |

### Transfers & adjustments

| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/inventory/transfers` | Create transfer |
| POST | `/api/inventory/transfers/{id}/dispatch` | Dispatch transfer |
| POST | `/api/inventory/transfers/{id}/receive` | Receive transfer |
| POST | `/api/inventory/adjustments` | Create adjustment |
| POST | `/api/inventory/adjustments/{id}/post` | Post adjustment |

### Reports

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/inventory/reports/stock-valuation` | Valuation report (optional `?warehouse_id=`) |
| GET | `/api/inventory/reports/movements` | Movement history (`?product_id=&warehouse_id=&from=&to=`) |
| GET | `/api/inventory/reports/forecast` | Sales forecast (`?lookback_days=&forecast_days=`) |

---

## Exceptions

| Exception | Thrown when |
|---|---|
| `PriceNotFoundException` | No active price found for product + tier + warehouse (when `price_not_found_throws = true`) |
| `InsufficientStockException` | Sale or transfer dispatch would result in negative available stock |
| `InvalidTransitionException` | A status transition is not allowed (e.g. posting an already-posted GRN) |

```php
use Centrex\Inventory\Exceptions\{InsufficientStockException, PriceNotFoundException};

try {
    Inventory::reserveStock($so->id);
} catch (InsufficientStockException $e) {
    // Notify user: $e->getMessage()
}

try {
    $price = Inventory::resolvePrice($product->id, 'fcom', $wh_china->id);
} catch (PriceNotFoundException $e) {
    // No fcom price configured for this product/warehouse
}
```

---

## Environment Variables

```env
INVENTORY_BASE_CURRENCY=BDT
INVENTORY_DB_CONNECTION=mysql          # optional dedicated DB connection
INVENTORY_TABLE_PREFIX=inv_
INVENTORY_WAC_PRECISION=4
INVENTORY_EXCHANGE_RATE_STALE_DAYS=1
INVENTORY_PRICE_NOT_FOUND_THROWS=true
INVENTORY_QTY_TOLERANCE=0.0001
INVENTORY_DEFAULT_SHIPPING_RATE_KG=0
INVENTORY_SEED_PRICE_TIERS=true
```

---

## Testing

```bash
composer lint          # apply Pint formatting
composer refacto       # apply Rector refactors
composer test:types    # PHPStan static analysis
composer test:unit     # Pest unit tests
composer test          # full suite
```

---

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for recent changes.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Credits

- [rochi88](https://github.com/centrex)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

# Pricing

## Price tiers

Seven tiers (enum `PriceTierCode`) are available:

| Enum case | Code string | Label |
| --- | --- | --- |
| `BASE` | `base` | Base Price |
| `B2B_WHOLESALE` | `b2b_wholesale` | B2B Wholesale |
| `B2B_RETAIL` | `b2b_retail` | B2B Retail |
| `B2B_DROPSHIP` | `b2b_dropship` | B2B Dropship |
| `B2C_RETAIL` | `b2c_retail` | B2C Retail |
| `B2C_ECOM` | `b2c_ecom` | B2C E-Commerce |
| `B2C_POS` | `b2c_pos` | B2C POS |

---

## Setting prices

```php
use Centrex\Inventory\Facades\Inventory;
use Centrex\Inventory\Enums\PriceTierCode;

// Global price (applies to all warehouses unless overridden)
Inventory::setPrice($product->id, PriceTierCode::B2C_RETAIL->value, 6500.00);

// Warehouse-specific override
Inventory::setPrice($product->id, PriceTierCode::B2C_RETAIL->value, 5900.00, $wh_china->id);

// Full options
Inventory::setPrice($product->id, 'b2b_wholesale', 5200.00, $wh->id, [
    'variant_id'     => $variant->id,    // null = applies to all variants
    'cost_price'     => 4000.00,
    'moq'            => 10,              // minimum order quantity
    'price_local'    => 5200.00,         // informational local currency amount
    'currency'       => 'BDT',
    'effective_from' => '2026-01-01',
    'effective_to'   => '2026-06-30',    // null = no expiry
    'is_active'      => true,
]);

// Time-limited promotion price
Inventory::setPrice($product->id, 'b2c_retail', 5500.00, null, [
    'effective_from' => '2026-06-01',
    'effective_to'   => '2026-06-30',
]);
```

---

## Resolving the effective price

```php
// Resolve with fallback chain:
// 1. warehouse-specific + variant  →  2. warehouse-specific  →  3. global + variant  →  4. global
$price = Inventory::resolvePrice(
    productId:   $product->id,
    tierCode:    'b2c_retail',
    warehouseId: $wh->id,
    date:        null,       // defaults to today
    variantId:   null,
);

echo $price->price_amount;        // resolved BDT price
echo $price->price_local;         // local currency amount (informational)
echo $price->isGlobal();          // true if warehouse_id is null
echo $price->isEffective();       // true if within effective_from/to range
echo $price->getPriceTierNameAttribute(); // "B2C Retail"
```

If no matching price is found and `INVENTORY_PRICE_NOT_FOUND_THROWS=true`, a `PriceNotFoundException` is thrown. Set to `false` to return `null` instead.

---

## Full price sheet

```php
// All tiers for a product at a warehouse
$sheet = Inventory::getPriceSheet($product->id, $wh->id, date: null, variantId: null);

// Returns a Collection:
// [
//   ['tier_code' => 'base',         'tier_name' => 'Base Price',     'price_amount' => 5000.00, 'price_local' => 5000.00, 'currency' => 'BDT', 'source' => 'global'],
//   ['tier_code' => 'b2b_wholesale', 'tier_name' => 'B2B Wholesale',  'price_amount' => 4800.00, 'price_local' => 4800.00, 'currency' => 'BDT', 'source' => 'warehouse'],
//   ['tier_code' => 'b2c_retail',    'tier_name' => 'B2C Retail',     'price_amount' => 5900.00, 'price_local' => 5900.00, 'currency' => 'BDT', 'source' => 'warehouse'],
//   ...
// ]
```

`source` is `'warehouse'` when a warehouse-specific price exists, `'global'` otherwise.

---

## Assigning a default tier to a customer

Set `price_tier_code` on the customer record. The sale order inherits it automatically but can be overridden per order or per line item.

```php
use Centrex\Inventory\Facades\Inventory;

$customer = Inventory::createCustomer([
    'name'            => 'Acme Corp',
    'price_tier_code' => 'b2b_wholesale',
    // ...
]);
```

See [sale-orders.md](sale-orders.md) for per-line tier overrides.

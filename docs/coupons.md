# Coupons

Coupons apply at the sale-order level. The discount amount and coupon metadata are snapshotted onto the order at creation time, so historic orders are unaffected if the coupon is later edited or deleted.

## Creating coupons

Coupons can be managed from the web UI under `/inventory/master-data/coupons` or created directly:

```php
use Centrex\Inventory\Models\Coupon;

Coupon::create([
    'code'                    => 'SAVE10',        // auto-uppercased
    'name'                    => 'Save 10%',
    'description'             => 'Q2 2026 campaign',
    'discount_type'           => 'percent',        // 'percent' | 'fixed'
    'discount_value'          => 10,               // 10% off
    'minimum_subtotal_amount' => 1000.00,          // base currency; 0 = no minimum
    'maximum_discount_amount' => 500.00,           // base currency; null = no cap
    'usage_limit'             => 100,              // null = unlimited
    'starts_at'               => now(),
    'ends_at'                 => now()->addMonth(),
    'is_active'               => true,
    'meta'                    => [],
]);
```

`fixed` coupon amounts are in the base currency and converted to the order currency at checkout.
`percent` coupons apply to the order subtotal before tax and before any manual order-level discount.

## Validating a coupon

```php
use Centrex\Inventory\Facades\Inventory;

$result = Inventory::resolveCouponDiscount(
    couponCode:        'SAVE10',
    subtotalLocal:     5000.00,      // order subtotal in order currency
    currency:          'BDT',
    orderedAt:         today(),
    documentType:      'order',      // 'order' | 'quotation'
    ignoreSaleOrderId: null,         // pass SO id when recalculating on an existing order
);

// On success:
// [
//   'discount_local'  => 500.00,     // discount in order currency
//   'discount_amount' => 500.00,     // discount in base currency
//   'coupon_id'       => 3,
//   'coupon_data'     => [...],      // snapshot of coupon fields
// ]
```

Validation rules checked:
- Coupon is active and `is_active = true`
- Current date is within `starts_at` / `ends_at`
- `subtotalLocal` ≥ `minimum_subtotal_amount`
- `usage_limit` not exceeded
- Calculated discount does not exceed `maximum_discount_amount`

## Applying a coupon to a sale order

Pass `coupon_code` when creating the sale order:

```php
$so = Inventory::createSaleOrder([
    'warehouse_id'    => $wh->id,
    'customer_id'     => $customer->id,
    'currency'        => 'BDT',
    'price_tier_code' => 'b2c_retail',
    'coupon_code'     => 'SAVE10',
    'items'           => [
        ['product_id' => $product->id, 'qty_ordered' => 2, 'unit_price_local' => 3000.00],
    ],
]);

echo $so->coupon_code;              // 'SAVE10'
echo $so->coupon_name;              // 'Save 10%'
echo $so->coupon_discount_local;    // 600.00 (10% of 6000)
echo $so->coupon_discount_amount;   // 600.00 (BDT)
echo $so->total_local;              // 5400.00
```

Both a manual `discount_local` and a coupon discount can be used on the same order. They are tracked separately.

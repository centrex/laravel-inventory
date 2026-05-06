# Sale Orders

## Create a sale order

```php
use Centrex\Inventory\Facades\Inventory;

$so = Inventory::createSaleOrder([
    'warehouse_id'    => $wh->id,
    'customer_id'     => $customer->id,
    'document_type'   => 'order',           // 'order' (default) | 'quotation'
    'price_tier_code' => 'b2b_wholesale',   // order-level default tier
    'currency'        => 'USD',
    'exchange_rate'   => 110.00,
    'tax_local'       => 0,
    'discount_local'  => 0,                 // manual order-level discount
    'coupon_code'     => 'SAVE10',          // optional — see coupons.md
    'ordered_at'      => today(),
    'notes'           => 'Urgent — air freight required',
    'created_by'      => auth()->id(),
    'items' => [
        [
            'product_id'       => $product->id,
            'qty_ordered'      => 50,
            'variant_id'       => null,
            // price_tier_code omitted → inherits order-level tier
            // unit_price_local omitted → auto-resolved from price table
            'discount_pct'     => 0,
        ],
        [
            'product_id'       => $product2->id,
            'qty_ordered'      => 10,
            'price_tier_code'  => 'b2c_retail',  // per-line tier override
            'unit_price_local' => 500.00,          // manual price override
            'discount_pct'     => 5,              // 5% line discount
            'notes'            => 'Special promo',
        ],
    ],
]);

// $so->so_number           => "SO-20260415-0001"
// $so->status              => SaleOrderStatus::DRAFT
// $so->credit_override_required => false (or true if over credit limit)
```

## Status transitions

```php
Inventory::confirmSaleOrder($so->id);   // DRAFT → CONFIRMED
Inventory::reserveStock($so->id);       // CONFIRMED → PROCESSING; qty_reserved ↑
Inventory::cancelSaleOrder($so->id);    // DRAFT|CONFIRMED → CANCELLED; releases reservations
```

## Fulfilling orders

### Full fulfillment

```php
Inventory::fulfillSaleOrder($so->id);
// Decrements qty_on_hand for all items
// Writes SALE_FULFILLMENT movements
// Posts COGS journal entry to accounting
// $so->status => FULFILLED
```

### Partial fulfillment

```php
Inventory::fulfillSaleOrder($so->id, fulfilledQtys: [
    $so->items[0]->product_id => 30,   // fulfill 30 of 50 ordered
    // items not listed are not fulfilled this time
]);
// $so->status => PARTIAL
// Call fulfillSaleOrder() again later to fulfill the remainder
```

## Gross profit & margin

```php
$so->refresh();
echo $so->cogs_amount;          // total cost of goods sold (WAC × fulfilled qty)
echo $so->grossProfitAmount();  // total_amount - cogs_amount
echo $so->grossMarginPct();     // gross profit / total * 100
```

## Convert quotation to sale order

```php
$quotation = Inventory::createSaleOrder([
    'document_type' => 'quotation',
    ...
]);

// Once accepted by the customer:
$so = Inventory::createSaleOrderFromQuotation($quotation->id, overrides: [
    'ordered_at'     => today(),
    'exchange_rate'  => 110.50,
]);
```

## Sale returns

Record a customer return against a fulfilled sale order.

```php
$return = Inventory::createSaleReturn([
    'sale_order_id' => $so->id,
    'warehouse_id'  => $wh->id,
    'customer_id'   => $customer->id,
    'returned_at'   => today(),
    'notes'         => 'Screen defect — customer complaint',
    'created_by'    => auth()->id(),
    'items' => [
        [
            'sale_order_item_id' => $so->items[0]->id,
            'qty_returned'       => 2,
            'unit_price_amount'  => 6500.00,   // selling price at time of sale
            'unit_cost_amount'   => 3105.25,   // WAC at time of fulfillment
        ],
    ],
]);

Inventory::postSaleReturn($return->id);
// Restores qty_on_hand (CUSTOMER_RETURN movement)
// Reverses COGS journal entry in accounting
// $return->status => POSTED
```

## Credit limit override

When `$so->credit_override_required === true`, the order cannot proceed to fulfillment without explicit approval:

```php
// Approved by a user with the inventory.sale-orders.approve-credit gate:
$so->update([
    'credit_override_approved_by' => auth()->id(),
    'credit_override_approved_at' => now(),
    'credit_override_notes'       => 'CEO approved one-time exception',
]);
```

# Stock Adjustments

Use adjustments to correct inventory discrepancies after a physical count, or to write off damaged, stolen, or expired stock.

## Create an adjustment

Supply the **actual** physical count (`qty_actual`). The system reads the current `qty_on_hand` automatically and calculates the delta.

```php
use Centrex\Inventory\Facades\Inventory;
use Centrex\Inventory\Enums\AdjustmentReason;

$adj = Inventory::createAdjustment([
    'warehouse_id' => $wh->id,
    'reason'       => AdjustmentReason::CYCLE_COUNT->value,
    'adjusted_at'  => today(),
    'notes'        => 'Monthly cycle count — bay 3',
    'created_by'   => auth()->id(),
    'items' => [
        [
            'product_id' => $product->id,
            'qty_actual'  => 145,   // system shows 150 → delta = -5
            'variant_id'  => null,
            'notes'       => 'Missing units found in bay 7 later',
        ],
        [
            'product_id' => $product2->id,
            'qty_actual'  => 62,    // system shows 60 → delta = +2 (found stock)
        ],
    ],
]);

// $adj->adjustment_number   => "ADJ-20260430-0001"
// $adj->status              => StockReceiptStatus::DRAFT
// $adj->items[0]->qty_system => 150
// $adj->items[0]->qty_delta  => -5
```

## Adjustment reasons

| Enum case | Description |
| --- | --- |
| `CYCLE_COUNT` | Periodic stock count reconciliation |
| `WRITE_OFF` | Permanently remove unusable stock from books |
| `DAMAGE` | Stock damaged in warehouse |
| `THEFT` | Stock confirmed as stolen |
| `EXPIRY` | Expired goods |
| `FOUND_STOCK` | Previously unrecorded stock discovered |
| `OTHER` | Catch-all for uncategorised adjustments |

## Post an adjustment

Posting is irreversible (except by voiding later). It:
1. Applies `qty_delta` to `WarehouseProduct.qty_on_hand`
2. Writes `ADJUSTMENT_IN` (positive delta) or `ADJUSTMENT_OUT` (negative delta) movements
3. Posts a journal entry to accounting (if enabled):
   - Gain (positive delta): DR Inventory / CR Inventory Gain (4900)
   - Loss (negative delta): DR Inventory Loss (5000) / CR Inventory

```php
$adj = Inventory::postAdjustment($adj->id);
// $adj->status => StockReceiptStatus::POSTED
```

## Write-off example

```php
$writeOff = Inventory::createAdjustment([
    'warehouse_id' => $wh->id,
    'reason'       => AdjustmentReason::DAMAGE->value,
    'adjusted_at'  => today(),
    'notes'        => 'Water damage — flood in warehouse B',
    'items' => [
        ['product_id' => $product->id, 'qty_actual' => 0],  // write off entire stock
    ],
]);
Inventory::postAdjustment($writeOff->id);
```

## Shipping estimate

Before creating a transfer, estimate the shipping cost:

```php
$estimate = Inventory::estimateShipping([
    ['product_id' => $product->id,  'qty' => 100],
    ['product_id' => $product2->id, 'qty' => 50],
]);

// [
//   'total_weight_kg'  => 40.0,
//   'estimated_cost'   => 600.0,   // at default_shipping_rate_per_kg from config
// ]
```

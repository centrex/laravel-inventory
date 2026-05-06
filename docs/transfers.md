# Inter-Warehouse Transfers

Moving stock between warehouses adds a per-kg shipping cost. This cost is allocated pro-rata across items by weight and added to the landed unit cost at the destination, feeding into the destination's WAC.

## Create a transfer

```php
use Centrex\Inventory\Facades\Inventory;

$transfer = Inventory::createTransfer([
    'from_warehouse_id'    => $wh_china->id,
    'to_warehouse_id'      => $wh_dhaka->id,
    'shipping_rate_per_kg' => 15.00,           // BDT per kg
    'notes'                => 'Monthly replenishment',
    'created_by'           => auth()->id(),
    'items' => [
        ['product_id' => $product->id, 'qty_sent' => 100, 'variant_id' => null],
        ['product_id' => $product2->id, 'qty_sent' => 50],
    ],
]);

// $transfer->transfer_number         => "TRF-20260415-0001"
// $transfer->status                  => TransferStatus::DRAFT
// $transfer->total_weight_kg         => auto-calculated from product weights
// $transfer->shipping_cost_amount    => total_weight_kg × shipping_rate_per_kg
```

## Transfer with explicit boxes

Items can be grouped into physical boxes for carrier-level tracking. If boxes are not provided, the system auto-creates a single box:

```php
$transfer = Inventory::createTransfer([
    'from_warehouse_id'    => $wh_china->id,
    'to_warehouse_id'      => $wh_dhaka->id,
    'shipping_rate_per_kg' => 15.00,
    'boxes' => [
        [
            'box_code'          => 'BOX-001',
            'measured_weight_kg' => 12.5,
            'notes'             => 'Fragile — electronics',
            'items' => [
                ['product_id' => $product->id, 'qty_sent' => 50],
            ],
        ],
        [
            'box_code'          => 'BOX-002',
            'measured_weight_kg' => 18.0,
            'items' => [
                ['product_id' => $product->id, 'qty_sent' => 50],
                ['product_id' => $product2->id, 'qty_sent' => 50],
            ],
        ],
    ],
]);
```

## Dispatch

Decrements source `qty_on_hand`, increments source `qty_in_transit`. Writes `TRANSFER_OUT` movements.

```php
Inventory::dispatchTransfer($transfer->id);
// $transfer->status => TransferStatus::IN_TRANSIT
// $transfer->shipped_at set to now()
```

## Receive at destination

Decrements source `qty_in_transit`, increments destination `qty_on_hand`. Allocates shipping cost by weight ratio and recalculates destination WAC using the landed unit cost.

```php
// Full receipt
Inventory::receiveTransfer($transfer->id);
// $transfer->status => TransferStatus::RECEIVED

// Partial receipt — supply received qty per product_id
Inventory::receiveTransfer($transfer->id, receivedQtys: [
    $product->id  => 80,   // receive 80 of 100 sent
    $product2->id => 50,
]);
// $transfer->status => TransferStatus::PARTIAL
// Call receiveTransfer() again to receive the remainder
```

## Multi-product shipping cost allocation example

```
Items:
  Product A — 20 units × 2 kg each = 40 kg
  Product B — 50 units × 0.1 kg each = 5 kg
  Total weight = 45 kg

Shipping: 45 kg × 80 BDT/kg = 3,600 BDT

Allocation:
  Product A: 40/45 × 3,600 = 3,200 BDT → 3,200/20 = 160 BDT/unit
  Product B:  5/45 × 3,600 =   400 BDT →   400/50 =   8 BDT/unit

Landed cost:
  Product A: source WAC + 160
  Product B: source WAC + 8
```

These landed costs feed into the destination `wac_amount` calculation.

## TransferItem fields (read-only after dispatch)

| Field | Description |
| --- | --- |
| `qty_sent` | Quantity dispatched |
| `qty_received` | Quantity actually received at destination |
| `unit_cost_source_amount` | WAC at source warehouse at time of dispatch |
| `weight_kg_total` | qty_sent × product.weight_kg |
| `shipping_allocated_amount` | Shipping cost allocated to this item |
| `unit_landed_cost_amount` | unit_cost_source + shipping_allocated / qty_sent |
| `wac_source_before_amount` | Source WAC before dispatch |
| `wac_dest_before_amount` | Destination WAC before receipt |
| `wac_dest_after_amount` | Destination WAC after receipt |

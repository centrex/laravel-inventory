# Purchase Orders & GRNs

## Create a purchase order

```php
use Centrex\Inventory\Facades\Inventory;

$po = Inventory::createPurchaseOrder([
    'warehouse_id'    => $wh->id,
    'supplier_id'     => $supplier->id,
    'document_type'   => 'order',          // 'order' (default) | 'requisition'
    'currency'        => 'CNY',
    'exchange_rate'   => 16.50,            // omit to auto-fetch latest stored rate
    'tax_local'       => 100.00,
    'shipping_local'  => 50.00,
    'other_charges_amount' => 0,
    'ordered_at'      => today(),
    'expected_at'     => today()->addDays(14),
    'notes'           => 'Spring restock order',
    'created_by'      => auth()->id(),
    'items'           => [
        [
            'product_id'       => $product->id,
            'qty_ordered'      => 200,
            'unit_price_local' => 180.00,  // CNY per unit
            'variant_id'       => null,
        ],
        [
            'product_id'       => $product2->id,
            'qty_ordered'      => 50,
            'unit_price_local' => 250.00,
        ],
    ],
]);

// $po->po_number          => "PO-20260410-0001"
// $po->status             => PurchaseOrderStatus::DRAFT
// $po->subtotal_local     => 200×180 + 50×250 = 48,500 CNY
// $po->total_amount       => BDT total (subtotal + tax + shipping) × rate
```

## Status transitions

```php
Inventory::submitPurchaseOrder($po->id);   // DRAFT → SUBMITTED
Inventory::confirmPurchaseOrder($po->id);  // SUBMITTED → CONFIRMED
Inventory::cancelPurchaseOrder($po->id);   // DRAFT|SUBMITTED → CANCELLED
```

## Convert requisition to purchase order

```php
// Create a requisition first
$req = Inventory::createPurchaseOrder([
    'document_type' => 'requisition',
    ...
]);

// Convert to a real PO
$po = Inventory::createPurchaseOrderFromRequisition($req->id, overrides: [
    'supplier_id'    => $supplier->id,
    'exchange_rate'  => 16.50,
]);
```

---

## Goods Received Notes (GRNs)

### Create a GRN

```php
$grn = Inventory::createStockReceipt($po->id, items: [
    [
        'purchase_order_item_id' => $po->items[0]->id,
        'qty_received'           => 150,         // partial receipt
        'unit_cost_local'        => 182.00,       // optional override; defaults to PO unit price
    ],
    [
        'purchase_order_item_id' => $po->items[1]->id,
        'qty_received'           => 50,
    ],
], options: [
    'received_at' => now(),
    'notes'       => 'First delivery — balance on next truck',
    'created_by'  => auth()->id(),
]);

// $grn->grn_number => "GRN-20260415-0001"
// $grn->status     => StockReceiptStatus::DRAFT
```

### Post the GRN

Posting is irreversible (except by voiding). It:
1. Increments `qty_on_hand` on `WarehouseProduct`
2. Recalculates WAC
3. Writes a `StockMovement` row (`PURCHASE_RECEIPT`)
4. Posts a journal entry to accounting (if enabled): DR Inventory / CR GRN Clearing

```php
$grn = Inventory::postStockReceipt($grn->id);
// $grn->status => StockReceiptStatus::POSTED

$stock = Inventory::getStockLevel($product->id, $wh->id);
echo $stock->qty_on_hand;  // 150
echo $stock->wac_amount;   // recalculated WAC
```

### Void a GRN

```php
$grn = Inventory::voidStockReceipt($grn->id);
// Writes compensating PURCHASE_RECEIPT movements (never deletes)
// Voids the accounting journal entry
// $grn->status => StockReceiptStatus::VOID
```

---

## Purchase returns

Return goods to the supplier after a GRN has been posted.

```php
$return = Inventory::createPurchaseReturn([
    'purchase_order_id' => $po->id,
    'warehouse_id'      => $wh->id,
    'supplier_id'       => $supplier->id,
    'returned_at'       => today(),
    'notes'             => 'Wrong SKU delivered — RMA approved',
    'created_by'        => auth()->id(),
    'items' => [
        [
            'purchase_order_item_id' => $po->items[0]->id,
            'qty_returned'           => 5,
            'unit_cost_amount'       => 2970.00,   // cost in base currency
        ],
    ],
]);

Inventory::postPurchaseReturn($return->id);
// Decrements qty_on_hand (RETURN_TO_SUPPLIER movement)
// Recalculates WAC
// $return->status => POSTED
```

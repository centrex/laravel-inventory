# Stock Queries & Reports

## Stock levels

```php
use Centrex\Inventory\Facades\Inventory;

// Single product at a warehouse (creates WarehouseProduct record if it doesn't exist)
$stock = Inventory::getStockLevel($product->id, $wh->id, variantId: null);
echo $stock->qty_on_hand;    // physical stock
echo $stock->qty_reserved;   // committed to open sale orders
echo $stock->qty_in_transit; // dispatched but not yet received at destination
echo $stock->qtyAvailable(); // qty_on_hand - qty_reserved
echo $stock->wac_amount;     // weighted average cost in base currency
echo $stock->totalValue();   // qty_on_hand × wac_amount
echo $stock->isLowStock();   // qtyAvailable() <= reorder_point

// All products at a warehouse
$all = Inventory::getStockLevels($wh->id);   // Collection of WarehouseProduct

// Low stock alerts (all warehouses, or filter to one)
$lowStock = Inventory::getLowStockItems(warehouseId: null);
$lowStock = Inventory::getLowStockItems($wh->id);

// Total inventory value in base currency
$total = Inventory::getStockValue(warehouseId: null);      // all warehouses
$value = Inventory::getStockValue($wh->id);                // single warehouse
```

---

## Valuation report

```php
$report = Inventory::stockValuationReport(warehouseId: null);
// Returns a Collection, one row per product×warehouse:
// [
//   'product_id'         => 1,
//   'product_name'       => 'Smartphone X1',
//   'sku'                => 'PHONE-X1',
//   'qty_on_hand'        => 145.0,
//   'wac_amount'         => 3105.2500,
//   'total_value'        => 450261.25,
// ]
```

---

## Movement history

Append-only audit log of all stock changes for a product at a warehouse.

```php
$movements = Inventory::getMovementHistory(
    productId:   $product->id,
    warehouseId: $wh->id,
    from:        '2026-01-01',
    to:          '2026-04-30',
);

foreach ($movements as $m) {
    echo "[{$m->moved_at}] ";
    echo $m->movement_type->label() . ' ' . $m->direction . ' ';
    echo "qty: {$m->qty} | before: {$m->qty_before} → after: {$m->qty_after}";
    echo " | WAC: {$m->wac_amount} | ref: {$m->reference_type} #{$m->reference_id}\n";
}
```

---

## Stock aging

Buckets current on-hand stock value by the age of the receipt it's actually traced back
to. This is reconstructed by replaying the full stock-movement ledger — purchases (plus
transfer-in / adjustment-in / opening stock / customer returns) as inbound, sales (plus
transfer-out / adjustment-out / return-to-supplier) as outbound — as a FIFO queue: every
inbound movement pushes a dated batch, every outbound movement consumes the oldest
batches first. What's left once the ledger is exhausted is the current on-hand stock,
split by the date each surviving unit actually arrived.

This matters whenever a product has more than one receipt on hand at once: 100 units
received 90 days ago plus 50 more received 5 days ago, with nothing sold in between, is
150 units on hand split across two ages — aging all 150 units from the 5-day-old receipt
(as an earlier, last-receipt-only version of this report did) would hide that two-thirds
of it is actually 90 days old. Replaying purchases *and* sales together is what correctly
attributes remaining qty back to its batch.

Warehouse×product×variant combinations where qty_on_hand exceeds what the replayed ledger
accounts for (stock that predates movement tracking, or an untracked manual adjustment)
have that shortfall bucketed as `'unknown'` rather than guessed at.

**The ledger itself comes from two sources, merged.** The `inv_stock_movements` audit trail
is used wherever it exists — it's precise and covers every movement type (transfers,
adjustments, returns, opening stock). Wherever it *doesn't* cover a warehouse×product×variant
— most commonly after migrating from a previous system that carried over full purchase-order
and sale-order records but not the derived movement history — it's backfilled straight from
the underlying documents instead of falling back to `'unknown'`: posted GRN items
(`qty_received − qty_damaged − qty_lost`, dated by the GRN's `received_at`) as inbound, and
fulfilled sale-order items (`qty_fulfilled`, dated by the order's `ordered_at`) as outbound.
A document only contributes a backfilled entry when no real movement row already references
it (matched by `reference_type`/`reference_id`), so a receipt or sale that already has its
own movement rows is never double-counted — this is what lets a warehouse with some
pre-migration (document-only) and some post-migration (movement-tracked) history for the
same product still age correctly as one combined ledger. Transfers, adjustments, and returns
are not backfilled this way — only movement history covers those — so a gap in *that* history
still lands in `'unknown'`.

```php
$rows = Inventory::stockAgingReport(warehouseId: null);
// Collection, one row per warehouse×product×variant with qty_on_hand > 0:
// [
//   'warehouse'            => 'Main Warehouse',
//   'sku'                  => 'PHONE-X1',
//   'product'              => 'Smartphone X1',
//   'qty_on_hand'          => 150.0,
//   'wac_amount'           => 3105.25,
//   'total_value_amount'   => 465787.50,
//   'oldest_days_in_stock' => 90,                 // age of the oldest surviving batch; null if untraceable
//   'buckets' => [
//       '0-30'    => ['qty' => 50.0,  'value' => 155262.50],
//       '31-60'   => ['qty' => 0.0,   'value' => 0.0],
//       '61-90'   => ['qty' => 100.0, 'value' => 310525.00],
//       '90+'     => ['qty' => 0.0,   'value' => 0.0],
//       'unknown' => ['qty' => 0.0,   'value' => 0.0],
//   ],
// ]

$summary = Inventory::stockAgingSummary(warehouseId: null);
// ['0-30' => x, '31-60' => x, '61-90' => x, '90+' => x, 'unknown' => x] — total stock value per bucket
```

---

## Due aging (customer receivables)

Buckets each customer's outstanding sale-order `due_amount` by days since the order date.
Uses the same "what counts as outstanding" rule as the customer credit snapshot: open
orders (confirmed/processing/partial) always count; fulfilled orders only count when they
carry a linked accounting invoice (sold on credit, not cash/COD).

```php
$rows = Inventory::dueAgingReport(customerId: null);
// Collection, one row per outstanding sale order:
// [
//   'customer_id'  => 7,
//   'customer'     => 'Acme Corp',
//   'so_number'    => 'SO-000123',
//   'ordered_at'   => Carbon('2026-05-01 ...'),
//   'due_amount'   => 11500.0,
//   'days_overdue' => 79,
//   'age_bucket'   => '61-90',                   // 0-30 | 31-60 | 61-90 | 90+ | unknown
// ]

$summary = Inventory::dueAgingSummary(customerId: null);
// ['0-30' => x, '31-60' => x, '61-90' => x, '90+' => x, 'unknown' => x] — total due per bucket
```

---

## Customer credit snapshot

```php
$snapshot = Inventory::customerCreditSnapshot($customer->id);
// [
//   'credit_limit_amount'  => 500000.0,
//   'outstanding_exposure' => 123400.0,   // open SO totals in base currency
//   'available_credit'     => 376600.0,
// ]
```

---

## Customer order history

```php
$orders = Inventory::customerHistory($customer->id, limit: 10);
```

---

## Sales forecast

The forecast analyses historical sales patterns and projects future demand, procurement needs, and cash flow.

```php
$forecast = Inventory::salesForecast(
    lookbackDays:  90,   // historical window to analyse
    forecastDays:  90,   // forward projection window
    productLimit:  50,   // top N products by sales volume
    customerLimit: 25,   // top N customers by revenue
);
```

### `$forecast['window']`

```
history_start, history_end, lookback_days, forecast_days
```

### `$forecast['summary']`

```
products_tracked          — number of products with sales history
products_at_risk          — products with projected stockout in forecast window
forecast_qty              — total projected units across all tracked products
forecast_revenue          — projected revenue (base currency)
required_procurement_cost — estimated cost to cover forecasted demand
forecast_cash_in          — projected receipts
forecast_cash_out         — projected procurement spend
forecast_cash_net         — net cash impact
```

### `$forecast['products']`

```
product_id, product_name, sku
history_qty          — units sold in lookback window
forecast_qty         — projected units in forecast window
avg_daily_qty        — average units per day (history)
avg_daily_revenue    — average revenue per day
forecast_gap_qty     — shortfall vs. current on-hand + pending supply
days_of_cover        — days until stockout at current velocity
pending_supply_qty   — qty on confirmed purchase orders
stockout_date        — projected stockout date (null if not at risk)
confidence           — high | medium | low (based on sales regularity)
```

### `$forecast['customers']`

```
customer_id, customer_name, orders_count
forecast_qty, forecast_revenue
```

### `$forecast['timeline']`

```
categories  — date labels
series      — {qty, revenue, cash_in, cash_out, net}
totals      — aggregate across all periods
```

Use `timeline` directly with chart components:

```blade
<livewire:tallui-bar-chart
    :series="[['name' => 'Forecast Qty', 'data' => $forecast['timeline']['series']['qty']]]"
    :categories="$forecast['timeline']['categories']"
    title="90-Day Sales Forecast"
/>
```

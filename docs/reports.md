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

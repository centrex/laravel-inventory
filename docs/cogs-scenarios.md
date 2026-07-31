# COGS & WAC — Common Issues and How to Fix Them

Everything on this page originates in the **costing engine** (WAC, receipts, transfers/shipments,
returns, adjustments) — the layer that decides *what number* becomes COGS before it's ever
posted to the ledger. For issues on the **accounting side** (a COGS journal entry with no
matching revenue entry, period-lock, multi-currency posting, report-level symptoms), see
[laravel-accounting's cogs-scenarios.md](../../laravel-accounting/docs/cogs-scenarios.md).

If you landed here from a report that "looks wrong," start with the symptom table, not the
detailed sections — most of these have a one-command diagnostic before you touch any data.

## Symptom → likely cause

| Symptom | Likely cause | Jump to |
| --- | --- | --- |
| COGS is much higher than revenue on a specific order/period | Orphaned COGS entry (no matching invoice) — this is actually an accounting-side issue | [laravel-accounting guide](../../laravel-accounting/docs/cogs-scenarios.md#scenario-1-orphaned-cogs-no-matching-revenue) |
| Average margin on the dashboard is way higher than your real margin | Unfulfilled orders (confirmed/processing) blended into the margin ratio | [§7](#7-dashboard-margin-includes-orders-that-havent-shipped-yet) |
| One product's COGS jumped after receiving/shipping stock from another warehouse | Wrong `weight_kg` skewed a multi-product shipment's landed-cost split | [§1](#1-wrong-productweight_kg-skews-a-multi-product-shipments-landed-cost) |
| A GRN was posted with the wrong unit cost and it's now baked into every sale since | Bad receipt cost blended into WAC | [§2](#2-a-bad-receipt-cost-is-baked-into-wac) |
| A sale order shipped in two batches has an oddly-priced item | `unit_cost_amount` only reflects the *last* fulfillment batch's WAC | [§3](#3-partial-fulfillment-doesnt-blend-cost-across-batches) |
| WAC dropped/spiked right after a damaged-goods receipt | Damaged/lost units miscategorized at GRN time | [§4](#4-damagedlost-units-miscounted-at-receipt) |
| Two products with the same supplier cost show different COGS | Product's `costing_method` (WAC/FIFO/LIFO) doesn't match how it's actually received | [§5](#5-costing_method-mismatch-fifolifo-vs-wac) |
| A sale return didn't restore the original margin | Return posted at *current* WAC, not the WAC at time of sale | [§6](#6-sale-returns-reverse-cogs-at-current-wac-not-original-wac) |
| Migrated/imported orders show $0 COGS with real revenue | Order never passed through `fulfillSaleOrder()` | [§8](#8-migrated-orders-never-passed-through-fulfillsaleorder) |
| Correcting a shipment's WAC didn't seem to change anything downstream | Forgot to also recalculate COGS for orders that already fulfilled from that stock | [§9](#9-forgetting-to-chain-into-cogs-recalculation-after-a-wac-fix) |
| An imported product's cost swings wildly month to month for no stock reason | Exchange rate drift on the receiving side | [§10](#10-exchange-rate-drift-on-imported-goods) |

---

## 1. Wrong `Product.weight_kg` skews a multi-product shipment's landed cost

**Mechanism:** `createInterWarehouseShipment()`/`createTransfer()` split a box's measured
shipping cost across the products inside it **by theoretical weight** (`qty × weight_kg`), not
evenly. If one product's `weight_kg` was wrong when the shipment was built, that product's share
of the shipping cost — and therefore its landed cost, and therefore the destination WAC it feeds
— is wrong. The box's *measured* weight is a physical fact and is never wrong; only the
per-product *split* of that weight is at risk.

**Symptom:** after fixing the product's weight, the shipment's box breakdown (ShipmentShowPage /
TransferShowPage) still shows the old split, and the destination WAC still reflects the wrong
landed cost.

**Fix:**
```bash
php artisan inventory:recalculate-shipment {shipment_id} --dry-run   # preview
php artisan inventory:recalculate-shipment {shipment_id}             # apply (asks to confirm)
```
Re-derives the weight split from the *current* `weight_kg`, corrects `shipping_allocated_amount`/
`unit_landed_cost_amount`, and — if the shipment was already received — re-blends the destination
WAC using the corrected landed cost. Skips (and reports) any line where something else has since
touched that warehouse/product's WAC, since a precise retroactive blend is no longer well-defined
at that point. See §9 for why you almost always want to run this together with a COGS
recalculation, not on its own.

---

## 2. A bad receipt cost is baked into WAC

**Mechanism:** `postStockReceipt()` blends the GRN's `unit_cost_amount` straight into WAC:
`newWac = (qtyBefore × wacBefore + qtyGood × unitCost) / (qtyBefore + qtyGood)`. A fat-fingered
unit cost (wrong currency, wrong decimal place, VAT included by mistake) permanently pollutes
WAC the moment it posts — every sale afterward inherits it until enough correctly-costed stock
dilutes it back down.

**Fix, if the WAC hasn't moved since:**
```php
Inventory::voidStockReceipt($grnId);
// ... fix the GRN's unit_cost_amount, then re-create/re-post it
```
`voidStockReceipt()` only un-blends WAC safely when it's still "the top of the cost stack" (i.e.
nothing else has changed `wac_amount` since this GRN posted) — otherwise it leaves the current
WAC untouched rather than guess, and you'll need a manual adjustment (see §4) to correct the
delta instead.

**If other stock has moved since:** you can't cleanly un-blend the mistake out of WAC. Post a
manual [`Adjustment`](adjustments.md) with `reason: 'other'` to bring `wac_amount` back to a
value you've computed by hand, and note the correction in the adjustment's `notes`.

---

## 3. Partial fulfillment doesn't blend cost across batches

**Mechanism:** `SaleOrderItem.unit_cost_amount` is **overwritten**, not blended, on every
`fulfillSaleOrder()` call — it always reflects the WAC at the *most recent* fulfillment, even
though `qty_fulfilled` accumulates across all batches. If a 100-unit order ships 60 units at WAC
৳50 and the remaining 40 at WAC ৳80 (stock was replenished in between), `unit_cost_amount` ends
up showing ৳80 for the whole line — but the **order-level `SaleOrder.cogs_amount` itself is
correct**, since `Inventory::fulfillSaleOrder()` adds each batch's *actual* cost
(`$so->cogs_amount + $totalCogs`) rather than re-deriving it from the item's overwritten
`unit_cost_amount`.

**Symptom:** `SaleOrderItem::lineCogsAmount()` (`qty_fulfilled × unit_cost_amount`) doesn't match
what was actually posted to the ledger for that line — it's a display/reporting artifact, not a
ledger error. Don't use `lineCogsAmount()` to reconcile against posted JEs for a multi-batch
fulfillment; use the JE lines themselves (`source_action = 'sale_fulfillment'`, one JE per
fulfillment call, see the accounting-side guide) if you need the true per-batch breakdown.

---

## 4. Damaged/lost units miscounted at receipt

**Mechanism:** `postStockReceipt()` only blends the **good** portion of a GRN
(`qty_received − qty_damaged − qty_lost`) into WAC and `qty_on_hand`. Damaged units go to the
separate `qty_damaged` bin (excluded from WAC); lost units are written off entirely (never touch
`qty_on_hand` or WAC). If a receiving clerk marks genuinely-good stock as damaged (or vice versa),
WAC is computed against the wrong quantity — a shortfall of "good" qty inflates WAC (same total
cost spread over fewer blended units); over-counting "good" qty dilutes it.

**Diagnose:** compare the GRN's `qty_damaged`/`qty_lost` against what was physically counted.
`getMovementHistory($productId, $warehouseId)` shows the `PURCHASE_RECEIPT` movement's blended
qty/WAC alongside any `ADJUSTMENT_OUT` rows logged for that same GRN's damaged/lost portion.

**Fix:** void and re-post the GRN with the corrected split (§2), or — if other stock has since
moved — a manual adjustment to both `qty_damaged` and `wac_amount`.

---

## 5. `costing_method` mismatch (FIFO/LIFO vs WAC)

**Mechanism:** `Product.costing_method` (`wac` | `fifo` | `lifo`) controls how
`fulfillSaleOrder()` prices an outgoing unit. For `fifo`/`lifo`, cost comes from **lot
selection** (oldest/newest receipt lot, via `Lot.unit_cost_amount`), not the blended
`WarehouseProduct.wac_amount` — the two numbers can legitimately differ for the same physical
unit. A product accidentally left on `wac` when the business actually tracks it lot-by-lot (or
vice versa) produces COGS that's internally consistent but doesn't match what the warehouse team
expects from their lot records.

**Diagnose:** check `Product.costing_method` against how the product is actually received —
does it come in identifiable batches with materially different unit costs (fifo/lifo territory),
or is it fungible bulk stock (wac territory)?

**Fix:** this is a configuration decision, not a data-correction command — changing
`costing_method` only affects *future* fulfillments; it doesn't retroactively re-cost stock
already sold under the old method.

---

## 6. Sale returns reverse COGS at *current* WAC, not original WAC

**Mechanism:** `createSaleReturn()` defaults each return line's `unit_cost_amount` to
`WarehouseProduct::wac_amount` **at return time** — even when the return is linked to the
original `sale_order_id` and could have looked up `SaleOrderItem::unit_cost_amount` (the cost
actually charged at fulfillment) instead. `postSaleReturn()` → `ErpIntegration::postSaleReturn()`
then posts `DR Inventory / CR COGS` using exactly that (possibly-current, possibly-stale)
`unit_cost_amount`. If WAC has moved since the original sale, the reversal doesn't net back to
what was actually charged, leaving a small (or large) permanent COGS residue.

**Fix:** when calling `createSaleReturn()`, explicitly pass `unit_cost_amount` per item using the
*original* sale's cost — read it off the linked `SaleOrderItem::unit_cost_amount` (or the
`sale_fulfillment` `StockMovement` row for that order/product) rather than relying on the
default:
```php
Inventory::createSaleReturn([
    'sale_order_id' => $so->id,
    'warehouse_id'  => $warehouseId,
    'items' => [
        ['product_id' => $item->product_id, 'qty_returned' => 2, 'unit_cost_amount' => $item->unit_cost_amount],
    ],
]);
```

---

## 7. Dashboard margin includes orders that haven't shipped yet

**Mechanism:** `SalesOrderProfitSummary::summarize()` (used by the dashboard's Sales Order Trend
and Sales by Employee cards) only excludes `draft`/`cancelled`/`returned` orders — `confirmed`,
`processing`, and `partial` orders are included with their **full revenue** but `cogs_amount`
still `0` (or partial), because `cogs_amount` is only populated by `fulfillSaleOrder()`. Orders
still in the sales pipeline read as ~100% margin and drag the blended average up, sometimes
drastically (46% shown vs. a real 14–30% margin is a one-line explanation, not a data-integrity
bug).

This was fixed to compute `net_profit`/`net_margin_pct` only over orders where
`cogs_amount > 0` — `revenue`/`orders_count` still reflect every order placed (an "orders
placed" figure), so they will not arithmetically reconcile against `net_profit`; that's
intentional. A `partial` order with *any* cogs posted still counts its full order revenue against
its partial cost, a known smaller residual version of the same approximation.

---

## 8. Migrated orders never passed through `fulfillSaleOrder()`

**Mechanism:** bulk-imported/migrated sale orders were inserted directly into the database —
`ErpIntegration::postSaleFulfillment()` was never called for them, so they have real revenue
(`total_amount`) but `cogs_amount = 0` and no `sale_fulfillment` journal entry at all. This is
the single biggest contributor to an inflated aggregate margin if your dataset has any migrated
history — worse than §7, since these orders may already be `fulfilled`/`completed` and still
show no cost at all.

**Diagnose:**
```php
SaleOrder::whereIn('status', ['fulfilled', 'completed'])
    ->where('total_amount', '>', 0)
    ->where('cogs_amount', 0)
    ->count();
```

**Fix:** `php artisan accounting:backfill-cogs --dry-run` (posts the missing COGS JE using
*current* WAC as the cost basis — see the accounting-side guide for the caveats on using
*current* rather than *historical* WAC for old orders).

---

## 9. Forgetting to chain into COGS recalculation after a WAC fix

**Mechanism:** `inventory:recalculate-shipment` only fixes the *destination warehouse's* WAC.
Any sale order that already fulfilled that product from that warehouse **before** you ran the
fix inherited the *old, wrong* WAC into its own `cogs_amount` and posted JE — the shipment fix
doesn't reach back and correct those independently.

**Fix:** `inventory:recalculate-shipment` chains into `accounting:recalculate-cogs`
automatically when it actually corrects a destination WAC — pass `--from`/`--to` to scope which
sale orders the follow-up considers:
```bash
php artisan inventory:recalculate-shipment {shipment_id} --from=2026-01-01 --to=2026-02-28
```
If you corrected WAC some other way (a manual adjustment, a direct DB fix), run
`accounting:recalculate-cogs` yourself — it's not automatic outside this one chained path.

---

## 10. Exchange rate drift on imported goods

**Mechanism:** a PO/Bill/Transfer/Shipment in a foreign currency locks its `exchange_rate` at
document-creation time (`unit_price_amount = unit_price_local × exchange_rate`). If that rate was
stale or wrong (fetched before a currency crash, entered by hand incorrectly), the receipt's
*base-currency* unit cost — and therefore WAC — is wrong even though the *foreign-currency* cost
was correct all along.

**Diagnose:** compare the document's `exchange_rate` against `Inventory::getExchangeRate($currency, $date)`
for that same date. `INVENTORY_EXCHANGE_RATE_LIVE_FETCH` controls whether a missing rate silently
falls back to a live API call (and persists it) vs. throwing — a stale/wrong *stored* rate isn't
caught by either path, since both trust whatever's in `oer_exchange_rates` for that day.

**Fix:** same remediation path as §2 (bad receipt cost) — void and re-post the receipt with the
corrected base-currency cost if WAC hasn't moved since, otherwise a manual adjustment.

---

## Related tooling

| Command | What it fixes | Lives in |
| --- | --- | --- |
| `inventory:recalculate-shipment {id}` | Weight-based landed cost + destination WAC for one shipment; chains into COGS recalculation | `laravel-erp` |
| `accounting:recalculate-cogs` | Re-derives COGS from *current* WAC for already-posted, single-JE fulfillments | `laravel-erp` |
| `accounting:backfill-cogs` | Posts *missing* COGS JEs for orders that skipped `fulfillSaleOrder()` | `laravel-erp` |
| `accounting:backfill-cogs --void-orphaned` | Voids COGS JEs that have no matching posted revenue | `laravel-erp` |

All four support `--dry-run`. See the [accounting-side guide](../../laravel-accounting/docs/cogs-scenarios.md)
for what each actually writes to the ledger and the period-lock rules governing them.

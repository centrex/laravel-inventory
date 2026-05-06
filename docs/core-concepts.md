# Core Concepts

## Weighted Average Cost (WAC)

Every product at every warehouse has a running WAC in the base currency. It recalculates on every goods-in movement using a `SELECT FOR UPDATE` row lock to prevent race conditions.

```
New WAC = (Current Stock Value + New Purchase Value)
          ────────────────────────────────────────────
                Current Qty + New Purchase Qty
```

**Example:**

| Event | Qty | Unit Cost | Total Value | WAC |
| --- | --- | --- | --- | --- |
| Opening | 100 | ৳ 50.00 | ৳ 5,000 | ৳ 50.00 |
| GRN (+50 @ ৳60) | +50 | ৳ 60.00 | +৳ 3,000 | (5,000+3,000)÷150 = **৳ 53.33** |
| Sale (−80) | −80 | ৳ 53.33 | COGS = ৳ 4,266 | ৳ 53.33 |
| Balance | 70 | ৳ 53.33 | ৳ 3,733 | ৳ 53.33 |

WAC is stored in `inv_warehouse_products.wac_amount` (decimal, 18,4). WAC precision is controlled by `INVENTORY_WAC_PRECISION` (default 4 decimal places).

---

## Stock Dimensions

`WarehouseProduct` tracks three quantity fields per product per warehouse:

| Field | Description |
| --- | --- |
| `qty_on_hand` | Physical stock currently in the warehouse |
| `qty_reserved` | Committed to confirmed, open sale orders |
| `qty_in_transit` | Dispatched from source warehouse, not yet received |

**Available qty** = `qty_on_hand − qty_reserved`

Overselling is prevented at the `reserveStock` step — if available qty < ordered qty, `InsufficientStockException` is thrown.

---

## Document Types

Both purchase orders and sale orders support two document types, enabling a draft-then-convert workflow:

| Model | `document_type` values | Convert with |
| --- | --- | --- |
| PurchaseOrder | `order` (default), `requisition` | `createPurchaseOrderFromRequisition()` |
| SaleOrder | `order` (default), `quotation` | `createSaleOrderFromQuotation()` |

---

## State Machines

All status transitions are enforced via `canTransitionTo()` on each status enum. Attempting an invalid transition throws `InvalidTransitionException`.

```
PurchaseOrder:  DRAFT → SUBMITTED → CONFIRMED → PARTIAL / RECEIVED
                                               → CANCELLED (from DRAFT or SUBMITTED only)

SaleOrder:      DRAFT → CONFIRMED → PROCESSING → PARTIAL / FULFILLED
                                               → CANCELLED (from DRAFT or CONFIRMED only)
                                               → RETURNED (from FULFILLED)

Transfer:       DRAFT → IN_TRANSIT → PARTIAL / RECEIVED
                                   → CANCELLED (from DRAFT only)

StockReceipt:   DRAFT → POSTED → VOID
Adjustment:     DRAFT → POSTED → VOID
```

---

## Multi-Currency

All financial amounts are stored in **two forms**:
- `*_local` / `*_amount` — local (document) currency and base currency (BDT)
- Exchange rate is locked at document creation time

Example on a PO in CNY (rate = 16.50):
- `unit_price_local` = 180.00 CNY
- `unit_price_amount` = 180.00 × 16.50 = 2,970.00 BDT

Exchange rates are managed via the `laravel-open-exchange-rates` package. See [exchange-rates.md](exchange-rates.md).

---

## Price Tiers

Seven tiers (enum `PriceTierCode`) control which price is resolved for a sale order:

| Code | Label | Typical use |
| --- | --- | --- |
| `BASE` | Base Price | Internal reference / cost-plus |
| `B2B_WHOLESALE` | B2B Wholesale | Large-volume trade buyers |
| `B2B_RETAIL` | B2B Retail | Trade buyers at retail quantities |
| `B2B_DROPSHIP` | B2B Dropship | Dropship partners |
| `B2C_RETAIL` | B2C Retail | Walk-in / direct retail |
| `B2C_ECOM` | B2C E-Commerce | Online store |
| `B2C_POS` | B2C POS | Point-of-sale terminal |

Prices can be set globally or overridden per warehouse. Resolution order: warehouse+variant → warehouse → global+variant → global.

---

## Stock Movements (Audit Log)

`StockMovement` is an append-only table — nothing is ever deleted or updated. Every qty change writes an immutable row. Voids write compensating rows.

| Movement type | Direction | Triggered by |
| --- | --- | --- |
| `PURCHASE_RECEIPT` | in | GRN posted |
| `SALE_FULFILLMENT` | out | SO fulfilled |
| `TRANSFER_OUT` | out | Transfer dispatched |
| `TRANSFER_IN` | in | Transfer received |
| `ADJUSTMENT_IN` | in | Adjustment posted (qty_actual > qty_system) |
| `ADJUSTMENT_OUT` | out | Adjustment posted (qty_actual < qty_system) |
| `OPENING_STOCK` | in | Initial stock loading |
| `RETURN_TO_SUPPLIER` | out | Purchase return posted |
| `CUSTOMER_RETURN` | in | Sale return posted |

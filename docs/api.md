# Web UI & REST API

## Web UI

Enable/disable with `INVENTORY_WEB_ENABLED` (default `true`). All routes live under `web_prefix` (default `inventory`) and are protected by `web_middleware` (default `['web', 'auth']`).

| URL | Description |
| --- | --- |
| `/inventory/dashboard` | Overview: per-warehouse stock values, low-stock alerts |
| `/inventory/master-data/warehouses` | Warehouse management |
| `/inventory/master-data/products` | Product catalog |
| `/inventory/master-data/product-categories` | Category tree |
| `/inventory/master-data/product-brands` | Brand list |
| `/inventory/master-data/customers` | Customer management |
| `/inventory/master-data/suppliers` | Supplier management |
| `/inventory/master-data/coupons` | Coupon management |
| `/inventory/purchase-orders` | Purchase order list |
| `/inventory/purchase-orders/create` | New purchase order / requisition |
| `/inventory/purchase-orders/{id}` | PO detail: items, GRN history, status actions |
| `/inventory/sale-orders` | Sale order list |
| `/inventory/sale-orders/create` | New sale order / quotation |
| `/inventory/sale-orders/{id}` | SO detail: items, fulfillment, credit status |
| `/inventory/returns/sale` | Sale return list |
| `/inventory/returns/purchase` | Purchase return list |
| `/inventory/transfers` | Transfer list |
| `/inventory/transfers/create` | New transfer (with box grouping) |
| `/inventory/transfers/{id}` | Transfer detail: boxes, items, receive actions |
| `/inventory/adjustments/create` | New stock adjustment |
| `/inventory/reports` | Valuation, movement history, forecast |
| `/inventory/pos` | Point-of-sale terminal |

### Async select endpoint

The async select component (used in forms for customer, supplier, product search) hits:

```
GET /inventory/select/customers?q=Rahman
GET /inventory/select/suppliers?q=Shenzhen
GET /inventory/select/sale-products?q=phone&warehouse_id=1
GET /inventory/select/purchase-products?q=phone
```

Returns `[{value, label, sublabel}, ...]`.

---

## REST API

Enable/disable with `INVENTORY_API_ENABLED` (default `true`). Base prefix: `api/inventory`. Default middleware: `['api', 'auth:sanctum']`.

### Exchange rates & pricing

| Method | Endpoint | Body / Query |
| --- | --- | --- |
| POST | `/api/inventory/exchange-rates/set` | `{currency, rate, date?, source?}` |
| GET | `/api/inventory/exchange-rates/convert-to-bdt` | `?currency=CNY&amount=100` |
| POST | `/api/inventory/pricing` | `{product_id, tier_code, price_amount, warehouse_id?, ...}` |
| GET | `/api/inventory/pricing/resolve` | `?product_id=1&tier_code=b2c_retail&warehouse_id=1` |
| GET | `/api/inventory/pricing/sheet` | `?product_id=1&warehouse_id=1` |

### Master data (CRUD)

All entities support standard CRUD. Replace `{entity}` with one of: `warehouses`, `product-categories`, `product-brands`, `products`, `product-variants`, `suppliers`, `customers`, `coupons`, `product-prices`, `warehouse-products`.

| Method | Endpoint |
| --- | --- |
| GET | `/api/inventory/{entity}` |
| POST | `/api/inventory/{entity}` |
| GET | `/api/inventory/{entity}/{id}` |
| PUT | `/api/inventory/{entity}/{id}` |
| DELETE | `/api/inventory/{entity}/{id}` |

### Purchase orders

| Method | Endpoint | Description |
| --- | --- | --- |
| POST | `/api/inventory/purchase-orders` | Create PO |
| POST | `/api/inventory/purchase-orders/{id}/submit` | Submit for confirmation |
| POST | `/api/inventory/purchase-orders/{id}/confirm` | Confirm PO |
| POST | `/api/inventory/purchase-orders/{id}/receive` | Receive items (partial or full) |
| POST | `/api/inventory/purchase-orders/{id}/cancel` | Cancel PO |
| POST | `/api/inventory/stock-receipts` | Create GRN |
| POST | `/api/inventory/stock-receipts/{id}/post` | Post GRN to inventory |
| POST | `/api/inventory/stock-receipts/{id}/void` | Void GRN |

### Sale orders

| Method | Endpoint | Description |
| --- | --- | --- |
| POST | `/api/inventory/sale-orders` | Create SO |
| POST | `/api/inventory/sale-orders/{id}/confirm` | Confirm SO |
| POST | `/api/inventory/sale-orders/{id}/reserve` | Reserve stock |
| POST | `/api/inventory/sale-orders/{id}/fulfill` | Fulfill SO (pass `{fulfilled_qtys}` for partial) |
| POST | `/api/inventory/sale-orders/{id}/cancel` | Cancel SO |

### Transfers & adjustments

| Method | Endpoint | Description |
| --- | --- | --- |
| POST | `/api/inventory/transfers` | Create transfer |
| POST | `/api/inventory/transfers/{id}/dispatch` | Dispatch transfer |
| POST | `/api/inventory/transfers/{id}/receive` | Receive (pass `{received_qtys}` for partial) |
| POST | `/api/inventory/adjustments` | Create adjustment |
| POST | `/api/inventory/adjustments/{id}/post` | Post adjustment to inventory |

### Reports

| Method | Endpoint | Query params |
| --- | --- | --- |
| GET | `/api/inventory/reports/stock-valuation` | `?warehouse_id=` |
| GET | `/api/inventory/reports/movements` | `?product_id=&warehouse_id=&from=&to=` |
| GET | `/api/inventory/reports/forecast` | `?lookback_days=90&forecast_days=90` |

All API responses are JSON via `JsonResource` classes.

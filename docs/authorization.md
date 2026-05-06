# Authorization Gates

## Super-gate

All granular gates fall back to `inventory-admin`. Grant this gate to bypass all individual checks:

```php
// AppServiceProvider::boot()
Gate::define('inventory-admin', fn ($user) => $user->hasRole(['admin', 'inventory-manager']));
```

Roles configured via `INVENTORY_ADMIN_ROLES` (comma-separated) are automatically granted `inventory-admin`.

## Overriding individual gates

```php
Gate::define('inventory.sale-orders.approve-credit', fn ($user) => $user->hasRole('finance-manager'));
Gate::define('inventory.adjustments.post',           fn ($user) => $user->hasRole('warehouse-manager'));
Gate::define('inventory.stock-receipts.post',        fn ($user) => $user->hasRole(['warehouse-manager', 'procurement']));
```

## Gate reference

### Master data

| Gate | Who should have it |
| --- | --- |
| `inventory.master-data.view` | All authenticated users |
| `inventory.master-data.manage` | Inventory managers, admins |

### Exchange rates & pricing

| Gate | Who should have it |
| --- | --- |
| `inventory.exchange-rates.view` | Finance, procurement |
| `inventory.exchange-rates.manage` | Finance managers |
| `inventory.pricing.view` | Sales, procurement |
| `inventory.pricing.manage` | Sales managers, admins |

### Purchase orders & GRNs

| Gate | Who should have it |
| --- | --- |
| `inventory.purchase-orders.view` | Procurement, warehouse |
| `inventory.purchase-orders.create` | Procurement officers |
| `inventory.purchase-orders.submit` | Procurement officers |
| `inventory.purchase-orders.confirm` | Procurement managers |
| `inventory.stock-receipts.create` | Warehouse staff |
| `inventory.stock-receipts.post` | Warehouse managers |
| `inventory.stock-receipts.void` | Warehouse managers, admins |

### Sale orders

| Gate | Who should have it |
| --- | --- |
| `inventory.sale-orders.view` | Sales, warehouse |
| `inventory.sale-orders.create` | Sales staff |
| `inventory.sale-orders.confirm` | Sales managers |
| `inventory.sale-orders.reserve` | Sales, warehouse |
| `inventory.sale-orders.fulfill` | Warehouse staff |
| `inventory.sale-orders.cancel` | Sales managers |
| `inventory.sale-orders.approve-credit` | Finance managers |
| `inventory.channels.checkout` | POS operators, e-commerce service accounts |

### Transfers

| Gate | Who should have it |
| --- | --- |
| `inventory.transfers.view` | Warehouse, logistics |
| `inventory.transfers.create` | Warehouse managers, logistics |
| `inventory.transfers.dispatch` | Warehouse managers |
| `inventory.transfers.receive` | Warehouse staff at destination |

### Adjustments

| Gate | Who should have it |
| --- | --- |
| `inventory.adjustments.view` | Warehouse, finance |
| `inventory.adjustments.create` | Warehouse staff |
| `inventory.adjustments.post` | Warehouse managers |

### Reports

| Gate | Who should have it |
| --- | --- |
| `inventory.reports.view` | Management, finance, warehouse managers |

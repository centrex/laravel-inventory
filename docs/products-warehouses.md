# Products & Warehouses

## Warehouses

```php
use Centrex\Inventory\Models\Warehouse;

$wh = Warehouse::create([
    'code'         => 'WH-BD-01',
    'name'         => 'Dhaka Warehouse',
    'country_code' => 'BD',
    'currency'     => 'BDT',   // native purchase/sale currency
    'is_default'   => true,
    'is_active'    => true,
    'address'      => '123 Industrial Area, Dhaka',
    'meta'         => [],
]);

// Get the default warehouse
$default = Inventory::defaultWarehouse();

// All warehouses
$warehouses = Warehouse::where('is_active', true)->get();
```

Key relations on `Warehouse`: `warehouseProducts`, `purchaseOrders`, `saleOrders`, `outboundTransfers`, `inboundTransfers`, `adjustments`, `stockMovements`.

---

## Product categories

```php
use Centrex\Inventory\Models\ProductCategory;

$category = ProductCategory::create([
    'name'       => 'Electronics',
    'slug'       => 'electronics',
    'parent_id'  => null,        // null = top-level
    'sort_order' => 1,
    'is_active'  => true,
]);

// Sub-category
$sub = ProductCategory::create([
    'name'      => 'Smartphones',
    'slug'      => 'smartphones',
    'parent_id' => $category->id,
]);
```

---

## Product brands

```php
use Centrex\Inventory\Models\ProductBrand;

$brand = ProductBrand::create([
    'name'       => 'Samsung',
    'slug'       => 'samsung',
    'sort_order' => 1,
    'is_active'  => true,
]);
```

---

## Products

```php
use Centrex\Inventory\Models\Product;

$product = Product::create([
    'category_id'  => $category->id,
    'brand_id'     => $brand->id,
    'sku'          => 'PHONE-X1',
    'name'         => 'Smartphone X1',
    'description'  => 'Flagship model 2026',
    'unit'         => 'pcs',
    'weight_kg'    => 0.350,    // required for transfer shipping cost allocation
    'barcode'      => '8801234567890',
    'is_active'    => true,
    'is_stockable' => true,     // false = service item, no stock tracking
    'meta'         => ['color' => 'black', 'storage' => '256GB'],
]);
```

Key relations on `Product`: `category`, `brand`, `variants`, `warehouseProducts`, `prices`, `purchaseOrderItems`, `saleOrderItems`, `stockMovements`.

---

## Product variants

```php
use Centrex\Inventory\Models\ProductVariant;

$variant = ProductVariant::create([
    'product_id' => $product->id,
    'sku'        => 'PHONE-X1-RED-128',
    'name'       => 'Red / 128GB',
    'barcode'    => '8801234567891',
    'weight_kg'  => 0.350,
    'is_active'  => true,
    'attributes' => ['color' => 'Red', 'storage' => '128GB'],
]);

// $variant->display_name returns "Smartphone X1 — Red / 128GB"
```

---

## Querying products

```php
use Centrex\Inventory\Facades\Inventory;

// Paginated list with optional search
$products = Inventory::listProducts(
    activeOnly:    true,
    stockableOnly: true,
    limit:         50,
    search:        'samsung',   // searches name, sku, barcode
);

$product  = Inventory::findProduct($id);
$cats     = Inventory::listProductCategories(activeOnly: true);
$category = Inventory::findProductCategory($id);
```

---

## WarehouseProduct (stock ledger)

Each product×warehouse combination has one `WarehouseProduct` row. The facade creates it automatically on first stock movement.

```php
use Centrex\Inventory\Facades\Inventory;

// Get or create (initialises all qtys to 0)
$wp = Inventory::getOrCreateWarehouseProduct($warehouseId, $productId, $variantId);

// Computed methods
$wp->qtyAvailable(); // qty_on_hand - qty_reserved
$wp->totalValue();   // qty_on_hand * wac_amount
$wp->isLowStock();   // qtyAvailable() <= reorder_point

// Set reorder point directly on the model
$wp->update(['reorder_point' => 20, 'reorder_qty' => 100, 'bin_location' => 'A-12']);
```

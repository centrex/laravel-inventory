# Variant Management

Use one product record for the family and one variant record for each sellable SKU.

Example:

| Record type | Example |
| --- | --- |
| Product | T-Shirt |
| Variant | T-Shirt / Red / M |
| Variant | T-Shirt / Red / L |
| Variant | T-Shirt / Blue / M |

## Practical Rule

Transaction lines must always point to the exact sellable item:

- Simple item: `product_id` is set and `variant_id` is empty.
- Variant item: both `product_id` and `variant_id` are set.

Do not create separate products for size/color options if they belong to the same product family. Use variants instead, because stock, WAC, price, barcode, returns, and movement history already support `variant_id`.

## Stock and Price

Stock is tracked per warehouse and per variant through `WarehouseProduct`.

Price lookup checks variant-specific prices first, then falls back to product-level prices. This lets you price a whole product family once, and override only the variants that need different pricing.

## Transaction UX

Sales and purchase forms now use a line selector:

- `p:{id}` means the line is a simple product.
- `v:{id}` means the line is a product variant.

The selector is only a UI value. Before saving, Livewire resolves it back into `product_id` and `variant_id`, so existing inventory posting logic continues to work with the existing database fields.

## Returns

When a return is created from a source order, select the original order line. The system copies the source line's `product_id`, `variant_id`, price/cost, and remaining returnable quantity. This avoids mixing two variants of the same product.

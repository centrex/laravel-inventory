<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, BelongsToMany, HasMany};

class ProductVariant extends Model
{
    use AddTablePrefix;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'product_variants';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'product_id', 'sku', 'name', 'barcode',
        'weight_kg', 'sort_order', 'is_active',
        'attributes', 'meta',
    ];

    protected $casts = [
        'weight_kg'  => 'decimal:4',
        'sort_order' => 'integer',
        'is_active'  => 'boolean',
        'attributes' => 'array',
        'meta'       => 'array',
    ];

    protected $appends = ['display_name'];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouseProducts(): HasMany
    {
        return $this->hasMany(WarehouseProduct::class, 'variant_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class, 'variant_id');
    }

    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'variant_id');
    }

    public function saleOrderItems(): HasMany
    {
        return $this->hasMany(SaleOrderItem::class, 'variant_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'variant_id');
    }

    /** Normalised attribute-value rows linked through the pivot. */
    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductVariantAttributeValue::class,
            $this->tablePrefix() . 'product_variant_attributes',
            'variant_id',
            'attribute_value_id',
        )->withPivot('attribute_type_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForProduct(Builder $query, int $productId): Builder
    {
        return $query->where('product_id', $productId);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // ── Accessors / helpers ───────────────────────────────────────────────────

    public function getDisplayNameAttribute(): string
    {
        return trim(($this->product?->name ? $this->product->name . ' / ' : '') . $this->name);
    }

    /** Returns an attribute value from the JSON bag by type slug (e.g. 'color'). */
    public function attribute(string $typeSlug): mixed
    {
        return ($this->attributes ?? [])[$typeSlug] ?? null;
    }

    /** Merge new attribute values into the JSON bag and save. */
    public function setAttributes(array $attributes): static
    {
        $this->attributes = array_merge($this->attributes ?? [], $attributes);
        $this->save();

        return $this;
    }

    /** Unset one attribute key from the JSON bag and save. */
    public function removeAttribute(string $typeSlug): static
    {
        $bag = $this->attributes ?? [];
        unset($bag[$typeSlug]);
        $this->attributes = $bag;
        $this->save();

        return $this;
    }

    /** Total qty on hand summed across all warehouses. */
    public function totalQtyOnHand(): float
    {
        return (float) $this->warehouseProducts()->sum('qty_on_hand');
    }

    /** Total qty available (on_hand − reserved) summed across all warehouses. */
    public function totalQtyAvailable(): float
    {
        return (float) $this->warehouseProducts()
            ->selectRaw('SUM(qty_on_hand - qty_reserved) as available')
            ->value('available');
    }

    /** Whether this variant is referenced by any committed transaction lines. */
    public function hasTransactionHistory(): bool
    {
        return $this->purchaseOrderItems()->exists()
            || $this->saleOrderItems()->exists()
            || $this->stockMovements()->exists();
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function tablePrefix(): string
    {
        return config('inventory.table_prefix') ?: 'inv_';
    }
}

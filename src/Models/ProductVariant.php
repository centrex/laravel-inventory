<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

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
        'product_id', 'sku', 'name', 'barcode', 'weight_kg', 'is_active', 'attributes', 'meta',
    ];

    protected $casts = [
        'weight_kg'   => 'decimal:4',
        'is_active'   => 'boolean',
        'attributes'  => 'array',
        'meta'        => 'array',
    ];

    protected $appends = [
        'display_name',
    ];

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

    public function getDisplayNameAttribute(): string
    {
        return trim(($this->product?->name ? $this->product->name . ' / ' : '') . $this->name);
    }
}

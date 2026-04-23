<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\{AddTablePrefix, HasPrimaryImage};
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use Spatie\MediaLibrary\HasMedia;

class Product extends Model implements HasMedia
{
    use AddTablePrefix;
    use HasPrimaryImage;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'products';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'category_id', 'brand_id', 'sku', 'name', 'description',
        'unit', 'weight_kg', 'barcode', 'is_active', 'is_stockable', 'meta',
    ];

    protected $casts = [
        'weight_kg'    => 'decimal:4',
        'is_active'    => 'boolean',
        'is_stockable' => 'boolean',
        'meta'         => 'array',
    ];

    protected $appends = [
        'primary_image_url',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(ProductBrand::class, 'brand_id');
    }

    public function warehouseProducts(): HasMany
    {
        return $this->hasMany(WarehouseProduct::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function saleOrderItems(): HasMany
    {
        return $this->hasMany(SaleOrderItem::class);
    }

    public function transferItems(): HasMany
    {
        return $this->hasMany(TransferItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function adjustmentItems(): HasMany
    {
        return $this->hasMany(AdjustmentItem::class);
    }
}

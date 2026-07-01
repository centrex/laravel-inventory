<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\{AddTablePrefix, HasPrimaryImage};
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\MediaLibrary\HasMedia;

/**
 * Inventory product master record.
 *
 * A product can have variants (colour, size, etc.) stored in {@see ProductVariant}.
 * Stock levels per warehouse are tracked in {@see WarehouseProduct}.
 * Selling prices per tier are stored in {@see ProductPrice}.
 *
 * @property int $id
 * @property int|null $category_id
 * @property int|null $brand_id
 * @property array|null $variant_names JSON map of dimension labels, e.g. {"size":"Large","color":"Red"}
 * @property string $sku
 * @property string $name
 * @property string|null $slug URL-friendly slug; auto-generated from name if blank
 * @property string|null $description
 * @property string|null $meta_title SEO page title override
 * @property string|null $meta_description SEO meta description override (max 500 chars)
 * @property string|null $unit Unit of measure (pcs, kg, …)
 * @property float|null $weight_kg
 * @property string|null $barcode
 * @property bool $is_active
 * @property bool $is_stockable False for service/non-physical items
 * @property string $costing_method 'wac' | 'fifo' | 'lifo'
 * @property array|null $meta Arbitrary JSON payload (e.g. default_price)
 * @property-read string      $display_name    Name with variant dimensions appended, e.g. "T-Shirt (Red, Large)"
 * @property-read string|null $primary_image_url
 */
class Product extends Model implements Auditable, HasMedia
{
    use AddTablePrefix;
    use AuditableTrait;
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

    protected static function booted(): void
    {
        static::creating(function (Product $product): void {
            if (!filled($product->slug)) {
                $product->slug = static::uniqueSlug(Str::slug((string) $product->name));
            }
        });

        static::updating(function (Product $product): void {
            if (!filled($product->slug)) {
                $product->slug = static::uniqueSlug(Str::slug((string) $product->name), $product->getKey());
            }
        });
    }

    private static function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $slug = $base ?: 'product';
        $suffix = 1;

        while (
            static::withTrashed()
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }

    protected $fillable = [
        'category_id', 'brand_id', 'variant_names', 'sku', 'name', 'slug', 'description',
        'meta_title', 'meta_description',
        'unit', 'weight_kg', 'barcode', 'is_active', 'is_stockable', 'costing_method', 'meta',
    ];

    protected $casts = [
        'weight_kg'     => 'decimal:4',
        'is_active'     => 'boolean',
        'is_stockable'  => 'boolean',
        'variant_names' => 'array',
        'meta'          => 'array',
    ];

    protected $appends = [
        'primary_image_url',
        'display_name',
    ];

    /**
     * Full product name including variant dimension values, e.g. "T-Shirt (Red, Large)".
     * variant_names is stored as JSON: {"size":"Large","color":"Red"}
     */
    public function getDisplayNameAttribute(): string
    {
        $dims = is_array($this->variant_names)
            ? array_values(array_filter($this->variant_names))
            : [];

        if (empty($dims)) {
            return (string) $this->name;
        }

        return $this->name . ' (' . implode(', ', $dims) . ')';
    }

    public function getActiveAttribute(): bool
    {
        return (bool) $this->is_active;
    }

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

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\{AddTablePrefix, HasPrimaryImage};
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\MediaLibrary\HasMedia;

class ProductCategory extends Model implements Auditable, HasMedia
{
    use AddTablePrefix;
    use AuditableTrait;
    use HasPrimaryImage;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'product_categories';
    }

    protected $fillable = [
        'parent_id', 'name', 'slug', 'description', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * The appended `primary_image_url` accessor resolves through getFirstMediaUrl(), which
     * queries the media table whenever the relation is not already loaded — one query per
     * model on any toArray()/toJson(), i.e. every API listing and every Livewire payload.
     * Eager-loading it turns that N+1 into a single query per result set.
     *
     * @var list<string>
     */
    protected $with = ['media'];

    protected $appends = [
        'primary_image_url',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }
}

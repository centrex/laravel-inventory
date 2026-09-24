<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\{AddTablePrefix, HasPrimaryImage};
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\MediaLibrary\HasMedia;

class ProductBrand extends Model implements Auditable, HasMedia
{
    use AddTablePrefix;
    use AuditableTrait;
    use HasPrimaryImage;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'product_brands';
    }

    protected $fillable = [
        'name', 'slug', 'description', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active'  => 'boolean',
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

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'brand_id');
    }
}

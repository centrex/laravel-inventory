<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\{AddTablePrefix, HasPrimaryImage};
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{HasMany, MorphTo};
use Spatie\MediaLibrary\HasMedia;

class Supplier extends Model implements HasMedia
{
    use AddTablePrefix;
    use HasPrimaryImage;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'suppliers';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'code', 'name', 'country_code', 'currency',
        'contact_name', 'contact_email', 'contact_phone',
        'address', 'is_active', 'modelable_type', 'modelable_id', 'accounting_vendor_id', 'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'meta'      => 'array',
    ];

    protected $appends = [
        'primary_image_url',
    ];

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function modelable(): MorphTo
    {
        return $this->morphTo();
    }
}

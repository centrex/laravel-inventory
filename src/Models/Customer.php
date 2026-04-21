<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\{AddTablePrefix, HasPrimaryImage};
use Centrex\Inventory\Enums\PriceTierCode;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{HasMany, MorphTo};
use Spatie\MediaLibrary\HasMedia;

class Customer extends Model implements HasMedia
{
    use AddTablePrefix;
    use HasPrimaryImage;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'customers';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'code', 'name', 'email', 'phone', 'currency', 'credit_limit_amount',
        'price_tier_code', 'is_active',
        'modelable_type', 'modelable_id', 'accounting_customer_id', 'meta',
    ];

    protected $casts = [
        'credit_limit_amount' => 'decimal:4',
        'is_active'           => 'boolean',
        'meta'                => 'array',
    ];

    protected $appends = [
        'primary_image_url',
    ];

    public function saleOrders(): HasMany
    {
        return $this->hasMany(SaleOrder::class);
    }

    public function modelable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getPriceTierNameAttribute(): ?string
    {
        return PriceTierCode::labelFor($this->price_tier_code);
    }
}

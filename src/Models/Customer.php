<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\{AddTablePrefix, HasPrimaryImage};
use Centrex\Inventory\Enums\PriceTierCode;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, MorphTo};
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
        'code', 'name', 'organization_name', 'email', 'phone', 'zone', 'area', 'demographic_segment', 'demographic_data', 'currency', 'credit_limit_amount',
        'price_tier_code', 'sales_owner_id', 'sales_owner_designation', 'sales_manager_id', 'sales_assistant_manager_id', 'sales_executive_id', 'is_active',
        'modelable_type', 'modelable_id', 'accounting_customer_id', 'meta',
    ];

    protected $casts = [
        'credit_limit_amount' => 'decimal:4',
        'is_active'           => 'boolean',
        'demographic_data'    => 'array',
        'meta'                => 'array',
    ];

    protected $appends = [
        'primary_image_url',
    ];

    public function saleOrders(): HasMany
    {
        return $this->hasMany(SaleOrder::class);
    }

    public function salesManager(): BelongsTo
    {
        return $this->belongsTo((string) config('auth.providers.users.model', 'App\\Models\\User'), 'sales_manager_id');
    }

    public function salesOwner(): BelongsTo
    {
        return $this->belongsTo((string) config('auth.providers.users.model', 'App\\Models\\User'), 'sales_owner_id');
    }

    public function salesAssistantManager(): BelongsTo
    {
        return $this->belongsTo((string) config('auth.providers.users.model', 'App\\Models\\User'), 'sales_assistant_manager_id');
    }

    public function salesExecutive(): BelongsTo
    {
        return $this->belongsTo((string) config('auth.providers.users.model', 'App\\Models\\User'), 'sales_executive_id');
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

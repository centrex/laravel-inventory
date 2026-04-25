<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\{AddTablePrefix, HasPrimaryImage};
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, MorphTo};
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
        'code', 'name', 'country_code', 'demographic_segment', 'demographic_data', 'currency',
        'contact_name', 'contact_email', 'contact_phone',
        'address', 'is_active', 'modelable_type', 'modelable_id', 'accounting_vendor_id',
        'purchase_manager_id', 'purchase_assistant_manager_id', 'purchase_executive_id', 'meta',
    ];

    protected $casts = [
        'is_active'        => 'boolean',
        'demographic_data' => 'array',
        'meta'             => 'array',
    ];

    protected $appends = [
        'primary_image_url',
    ];

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function purchaseManager(): BelongsTo
    {
        return $this->belongsTo((string) config('auth.providers.users.model', 'App\\Models\\User'), 'purchase_manager_id');
    }

    public function purchaseAssistantManager(): BelongsTo
    {
        return $this->belongsTo((string) config('auth.providers.users.model', 'App\\Models\\User'), 'purchase_assistant_manager_id');
    }

    public function purchaseExecutive(): BelongsTo
    {
        return $this->belongsTo((string) config('auth.providers.users.model', 'App\\Models\\User'), 'purchase_executive_id');
    }

    public function modelable(): MorphTo
    {
        return $this->morphTo();
    }
}

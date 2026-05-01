<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\{AddTablePrefix, HasPrimaryImage};
use Centrex\Inventory\Enums\PriceTierCode;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, MorphTo};
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\MediaLibrary\HasMedia;

class Customer extends Model implements Auditable, HasMedia
{
    use AuditableTrait;
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
        'geo'                 => 'array',
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

    public function getZoneAttribute(): string|int|null
    {
        return $this->geoValue('zone')
            ?? $this->geoValue('zone_name')
            ?? $this->geoValue('zone_id');
    }

    public function setZoneAttribute(mixed $value): void
    {
        $this->setGeoValue('zone', $value);
    }

    public function getAreaAttribute(): string|int|null
    {
        return $this->geoValue('area')
            ?? $this->geoValue('area_name')
            ?? $this->geoValue('upazila_name')
            ?? $this->geoValue('district_name');
    }

    public function setAreaAttribute(mixed $value): void
    {
        $this->setGeoValue('area', $value);
    }

    public function getDemographicSegmentAttribute(): ?string
    {
        return $this->geoValue('demographic_segment')
            ?? $this->geoValue('segment');
    }

    public function setDemographicSegmentAttribute(mixed $value): void
    {
        $this->setGeoValue('demographic_segment', $value);
    }

    public function getDemographicDataAttribute(): array
    {
        $value = $this->geoValue('demographic_data');

        return is_array($value) ? $value : [];
    }

    public function setDemographicDataAttribute(mixed $value): void
    {
        $this->setGeoValue('demographic_data', is_array($value) ? $value : null);
    }

    private function geoValue(string $key): mixed
    {
        $geo = $this->geoArray();

        return $geo[$key] ?? null;
    }

    private function setGeoValue(string $key, mixed $value): void
    {
        $geo = $this->geoArray();

        if ($value === null || $value === '') {
            unset($geo[$key]);
        } else {
            $geo[$key] = $value;
        }

        $this->attributes['geo'] = $geo === [] ? null : json_encode($geo, JSON_THROW_ON_ERROR);
    }

    private function geoArray(): array
    {
        $geo = $this->attributes['geo'] ?? null;

        if (is_array($geo)) {
            return $geo;
        }

        if (is_string($geo) && $geo !== '') {
            $decoded = json_decode($geo, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}

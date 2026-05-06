<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\{AddTablePrefix, HasPrimaryImage};
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, MorphTo};
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\MediaLibrary\HasMedia;

class Supplier extends Model implements Auditable, HasMedia
{
    use AddTablePrefix;
    use AuditableTrait;
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
        'is_active' => 'boolean',
        'geo'       => 'array',
        'meta'      => 'array',
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

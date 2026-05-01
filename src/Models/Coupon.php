<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Coupon extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'coupons';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'code',
        'name',
        'description',
        'discount_type',
        'discount_value',
        'minimum_subtotal_amount',
        'maximum_discount_amount',
        'usage_limit',
        'is_active',
        'starts_at',
        'ends_at',
        'meta',
    ];

    protected $casts = [
        'discount_value'          => 'decimal:4',
        'minimum_subtotal_amount' => 'decimal:4',
        'maximum_discount_amount' => 'decimal:4',
        'usage_limit'             => 'integer',
        'is_active'               => 'boolean',
        'starts_at'               => 'datetime',
        'ends_at'                 => 'datetime',
        'meta'                    => 'array',
    ];

    public function saleOrders(): HasMany
    {
        return $this->hasMany(SaleOrder::class);
    }

    protected function setCodeAttribute(?string $value): void
    {
        $this->attributes['code'] = $value !== null ? strtoupper(trim($value)) : null;
    }
}

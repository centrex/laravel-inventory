<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\{AddTablePrefix, HasTenant};
use Centrex\Inventory\Enums\ShipmentStatus;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Shipment extends Model implements Auditable
{
    use AddTablePrefix;
    use HasTenant;
    use AuditableTrait;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'shipments';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'shipment_number', 'from_warehouse_id', 'to_warehouse_id',
        'status', 'total_weight_kg',
        'shipping_rate_per_kg', 'shipping_cost_amount',
        'notes', 'shipped_at', 'received_at', 'created_by',
    ];

    protected $casts = [
        'status'               => ShipmentStatus::class,
        'total_weight_kg'      => 'decimal:4',
        'shipping_rate_per_kg' => 'decimal:4',
        'shipping_cost_amount' => 'decimal:4',
        'shipped_at'           => 'datetime',
        'received_at'          => 'datetime',
    ];

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    public function boxes(): HasMany
    {
        return $this->hasMany(TransferBox::class, 'shipment_id');
    }
}

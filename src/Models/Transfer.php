<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Centrex\Inventory\Enums\TransferStatus;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Transfer extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'transfers';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'transfer_number', 'sale_order_id', 'warehouse_id',
        'from_warehouse_id', 'to_warehouse_id',
        'shipping_rate_per_kg', 'total_weight_kg', 'shipping_cost_amount',
        'carrier', 'tracking_number', 'status',
        'notes', 'dispatched_at', 'estimated_delivery_at', 'shipped_at', 'received_at', 'created_by',
    ];

    protected $casts = [
        'status'                => TransferStatus::class,
        'dispatched_at'         => 'datetime',
        'estimated_delivery_at' => 'datetime',
        'shipped_at'            => 'datetime',
        'received_at'           => 'datetime',
    ];

    public function saleOrder(): BelongsTo
    {
        return $this->belongsTo(SaleOrder::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

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
        return $this->hasMany(TransferItem::class);
    }

    public function boxes(): HasMany
    {
        return $this->hasMany(TransferBox::class);
    }
}

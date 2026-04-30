<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class Shipment extends Model
{
    use AddTablePrefix;

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
        'shipment_number', 'sale_order_id', 'warehouse_id',
        'carrier', 'tracking_number', 'status',
        'notes', 'dispatched_at', 'estimated_delivery_at', 'created_by',
    ];

    protected $casts = [
        'dispatched_at'         => 'datetime',
        'estimated_delivery_at' => 'datetime',
    ];

    public function saleOrder(): BelongsTo
    {
        return $this->belongsTo(SaleOrder::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }
}

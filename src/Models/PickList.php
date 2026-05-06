<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\{AddTablePrefix};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class PickList extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'pick_lists';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'pick_number', 'sale_order_id', 'warehouse_id', 'assigned_to',
        'status', 'notes', 'picked_at', 'created_by',
    ];

    protected $casts = [
        'picked_at' => 'datetime',
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
        return $this->hasMany(PickListItem::class);
    }

    public function isPicked(): bool
    {
        return $this->status === 'picked';
    }
}

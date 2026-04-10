<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Centrex\Inventory\Enums\{AdjustmentReason, StockReceiptStatus};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class Adjustment extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'adjustments';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'adjustment_number', 'warehouse_id', 'reason',
        'notes', 'status', 'adjusted_at', 'created_by',
    ];

    protected $casts = [
        'reason'      => AdjustmentReason::class,
        'status'      => StockReceiptStatus::class,
        'adjusted_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AdjustmentItem::class);
    }
}

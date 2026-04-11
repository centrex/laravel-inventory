<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdjustmentItem extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'adjustment_items';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'adjustment_id', 'product_id',
        'qty_system', 'qty_actual', 'qty_delta',
        'unit_cost_amount', 'notes',
    ];

    protected $casts = [
        'qty_system'    => 'decimal:4',
        'qty_actual'    => 'decimal:4',
        'qty_delta'     => 'decimal:4',
        'unit_cost_amount' => 'decimal:4',
    ];

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(Adjustment::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

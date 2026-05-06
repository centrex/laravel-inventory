<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Centrex\Inventory\Enums\MovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, MorphTo};

class StockMovement extends Model
{
    use AddTablePrefix;

    public const UPDATED_AT = null; // append-only — no updated_at

    protected function getTableSuffix(): string
    {
        return 'stock_movements';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'warehouse_id', 'product_id', 'variant_id', 'lot_id',
        'movement_type', 'direction',
        'qty', 'qty_before', 'qty_after',
        'unit_cost_amount', 'wac_amount',
        'reference_type', 'reference_id',
        'notes', 'moved_at', 'created_by',
    ];

    protected $casts = [
        'movement_type'    => MovementType::class,
        'qty'              => 'decimal:4',
        'qty_before'       => 'decimal:4',
        'qty_after'        => 'decimal:4',
        'unit_cost_amount' => 'decimal:4',
        'wac_amount'       => 'decimal:4',
        'moved_at'         => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}

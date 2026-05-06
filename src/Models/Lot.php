<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\{AddTablePrefix};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Lot extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;

    protected function getTableSuffix(): string
    {
        return 'lots';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'lot_number', 'product_id', 'variant_id', 'warehouse_id',
        'purchase_order_item_id',
        'manufactured_at', 'expires_at',
        'qty_initial', 'qty_on_hand', 'unit_cost_amount',
        'notes',
    ];

    protected $casts = [
        'manufactured_at'  => 'date',
        'expires_at'       => 'date',
        'qty_initial'      => 'decimal:4',
        'qty_on_hand'      => 'decimal:4',
        'unit_cost_amount' => 'decimal:4',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function serialNumbers(): HasMany
    {
        return $this->hasMany(SerialNumber::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isExpiringWithin(int $days): bool
    {
        return $this->expires_at !== null && $this->expires_at->diffInDays(now(), false) >= -$days;
    }

    public function qtyAvailable(): float
    {
        return max(0.0, (float) $this->qty_on_hand);
    }
}

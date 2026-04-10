<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Centrex\Inventory\Enums\SaleOrderStatus;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class SaleOrder extends Model
{
    use AddTablePrefix;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'sale_orders';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'so_number', 'warehouse_id', 'customer_id', 'price_tier_id',
        'currency', 'exchange_rate_bdt',
        'subtotal_local', 'subtotal_bdt',
        'tax_local', 'tax_bdt',
        'discount_local', 'discount_bdt',
        'total_local', 'total_bdt',
        'cogs_bdt', 'status', 'ordered_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'status'            => SaleOrderStatus::class,
        'exchange_rate_bdt' => 'decimal:8',
        'subtotal_local'    => 'decimal:4',
        'subtotal_bdt'      => 'decimal:4',
        'tax_local'         => 'decimal:4',
        'tax_bdt'           => 'decimal:4',
        'discount_local'    => 'decimal:4',
        'discount_bdt'      => 'decimal:4',
        'total_local'       => 'decimal:4',
        'total_bdt'         => 'decimal:4',
        'cogs_bdt'          => 'decimal:4',
        'ordered_at'        => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function priceTier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleOrderItem::class);
    }

    public function grossProfitBdt(): float
    {
        return (float) $this->total_bdt - (float) $this->cogs_bdt;
    }

    public function grossMarginPct(): float
    {
        if ((float) $this->total_bdt == 0.0) {
            return 0.0;
        }

        return round($this->grossProfitBdt() / (float) $this->total_bdt * 100, 2);
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\{AddTablePrefix};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerProductStat extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'customer_product_stats';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'customer_id',
        'product_id',
        'variant_id',
        'total_orders',
        'total_qty_ordered',
        'total_qty_fulfilled',
        'total_revenue_amount',
        'avg_unit_price_amount',
        'avg_order_interval_days',
        'total_return_qty',
        'return_rate_pct',
        'first_ordered_at',
        'last_ordered_at',
    ];

    protected $casts = [
        'total_orders'            => 'integer',
        'total_qty_ordered'       => 'float',
        'total_qty_fulfilled'     => 'float',
        'total_revenue_amount'    => 'float',
        'avg_unit_price_amount'   => 'float',
        'avg_order_interval_days' => 'float',
        'total_return_qty'        => 'float',
        'return_rate_pct'         => 'float',
        'first_ordered_at'        => 'datetime',
        'last_ordered_at'         => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}

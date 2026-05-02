<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\{AddTablePrefix, HasTenant};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierProductStat extends Model
{
    use AddTablePrefix;
    use HasTenant;

    protected function getTableSuffix(): string
    {
        return 'supplier_product_stats';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'supplier_id',
        'product_id',
        'variant_id',
        'total_orders',
        'total_qty_ordered',
        'total_qty_received',
        'total_cost_amount',
        'avg_unit_cost_amount',
        'min_unit_cost_amount',
        'max_unit_cost_amount',
        'avg_lead_time_days',
        'on_time_receipt_rate_pct',
        'fulfillment_rate_pct',
        'first_ordered_at',
        'last_ordered_at',
    ];

    protected $casts = [
        'total_orders'             => 'integer',
        'total_qty_ordered'        => 'float',
        'total_qty_received'       => 'float',
        'total_cost_amount'        => 'float',
        'avg_unit_cost_amount'     => 'float',
        'min_unit_cost_amount'     => 'float',
        'max_unit_cost_amount'     => 'float',
        'avg_lead_time_days'       => 'float',
        'on_time_receipt_rate_pct' => 'float',
        'fulfillment_rate_pct'     => 'float',
        'first_ordered_at'         => 'datetime',
        'last_ordered_at'          => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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

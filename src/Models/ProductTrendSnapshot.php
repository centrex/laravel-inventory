<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductTrendSnapshot extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'product_trend_snapshots';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'product_id',
        'variant_id',
        'warehouse_id',
        'snapshot_date',
        'period',
        'qty_sold',
        'qty_purchased',
        'qty_returned_sale',
        'qty_returned_purchase',
        'revenue_amount',
        'cogs_amount',
        'gross_profit_amount',
        'gross_margin_pct',
        'avg_sell_price',
        'avg_cost_amount',
        'wac_snapshot',
        'qty_on_hand_snapshot',
        'orders_count',
        'customers_count',
    ];

    protected $casts = [
        'snapshot_date'         => 'date',
        'qty_sold'              => 'float',
        'qty_purchased'         => 'float',
        'qty_returned_sale'     => 'float',
        'qty_returned_purchase' => 'float',
        'revenue_amount'        => 'float',
        'cogs_amount'           => 'float',
        'gross_profit_amount'   => 'float',
        'gross_margin_pct'      => 'float',
        'avg_sell_price'        => 'float',
        'avg_cost_amount'       => 'float',
        'wac_snapshot'          => 'float',
        'qty_on_hand_snapshot'  => 'float',
        'orders_count'          => 'integer',
        'customers_count'       => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}

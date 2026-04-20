<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReturnItem extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'sale_return_items';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'sale_return_id',
        'sale_order_item_id',
        'product_id',
        'qty_returned',
        'unit_price_amount',
        'unit_cost_amount',
        'line_total_amount',
        'notes',
    ];

    protected $casts = [
        'qty_returned'      => 'decimal:4',
        'unit_price_amount' => 'decimal:4',
        'unit_cost_amount'  => 'decimal:4',
        'line_total_amount' => 'decimal:4',
    ];

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function saleOrderItem(): BelongsTo
    {
        return $this->belongsTo(SaleOrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

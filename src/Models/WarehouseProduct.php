<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseProduct extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'warehouse_products';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'warehouse_id', 'product_id', 'variant_id',
        'qty_on_hand', 'qty_reserved', 'qty_in_transit',
        'wac_amount', 'reorder_point', 'reorder_qty', 'bin_location',
    ];

    protected $casts = [
        'qty_on_hand'    => 'decimal:4',
        'qty_reserved'   => 'decimal:4',
        'qty_in_transit' => 'decimal:4',
        'wac_amount'     => 'decimal:4',
        'reorder_point'  => 'decimal:4',
        'reorder_qty'    => 'decimal:4',
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

    public function qtyAvailable(): float
    {
        return (float) $this->qty_on_hand - (float) $this->qty_reserved;
    }

    public function totalValue(): float
    {
        return round((float) $this->qty_on_hand * (float) $this->wac_amount, (int) config('inventory.wac_precision', 4));
    }

    public function isLowStock(): bool
    {
        if ($this->reorder_point === null) {
            return false;
        }

        return $this->qtyAvailable() <= (float) $this->reorder_point;
    }
}

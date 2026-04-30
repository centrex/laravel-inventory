<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PickListItem extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'pick_list_items';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'pick_list_id', 'sale_order_item_id', 'product_id', 'variant_id',
        'lot_id', 'bin_location', 'qty_to_pick', 'qty_picked', 'serial_numbers',
    ];

    protected $casts = [
        'qty_to_pick'    => 'decimal:4',
        'qty_picked'     => 'decimal:4',
        'serial_numbers' => 'array',
    ];

    public function pickList(): BelongsTo
    {
        return $this->belongsTo(PickList::class);
    }

    public function saleOrderItem(): BelongsTo
    {
        return $this->belongsTo(SaleOrderItem::class);
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
}

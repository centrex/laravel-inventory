<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class PurchaseOrderItem extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'purchase_order_items';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'purchase_order_id', 'product_id',
        'qty_ordered', 'qty_received',
        'unit_price_local', 'unit_price_bdt',
        'line_total_local', 'line_total_bdt',
        'notes',
    ];

    protected $casts = [
        'qty_ordered'      => 'decimal:4',
        'qty_received'     => 'decimal:4',
        'unit_price_local' => 'decimal:4',
        'unit_price_bdt'   => 'decimal:4',
        'line_total_local' => 'decimal:4',
        'line_total_bdt'   => 'decimal:4',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockReceiptItems(): HasMany
    {
        return $this->hasMany(StockReceiptItem::class);
    }

    public function qtyPending(): float
    {
        return max(0.0, (float) $this->qty_ordered - (float) $this->qty_received);
    }
}

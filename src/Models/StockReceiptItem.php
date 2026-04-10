<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReceiptItem extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'stock_receipt_items';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'stock_receipt_id', 'purchase_order_item_id', 'product_id',
        'qty_received',
        'unit_cost_local', 'unit_cost_bdt', 'exchange_rate_bdt',
        'wac_before_bdt', 'wac_after_bdt', 'notes',
    ];

    protected $casts = [
        'qty_received'      => 'decimal:4',
        'unit_cost_local'   => 'decimal:4',
        'unit_cost_bdt'     => 'decimal:4',
        'exchange_rate_bdt' => 'decimal:8',
        'wac_before_bdt'    => 'decimal:4',
        'wac_after_bdt'     => 'decimal:4',
    ];

    public function stockReceipt(): BelongsTo
    {
        return $this->belongsTo(StockReceipt::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

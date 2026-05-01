<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class StockReceiptItem extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;

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
        'stock_receipt_id', 'purchase_order_item_id', 'product_id', 'variant_id',
        'lot_id', 'serial_numbers',
        'qty_received', 'qty_damaged', 'qty_lost',
        'unit_cost_local', 'unit_cost_amount', 'exchange_rate',
        'wac_before_amount', 'wac_after_amount', 'notes',
    ];

    protected $casts = [
        'qty_received'      => 'decimal:4',
        'qty_damaged'       => 'decimal:4',
        'qty_lost'          => 'decimal:4',
        'unit_cost_local'   => 'decimal:4',
        'unit_cost_amount'  => 'decimal:4',
        'exchange_rate'     => 'decimal:8',
        'wac_before_amount' => 'decimal:4',
        'wac_after_amount'  => 'decimal:4',
        'serial_numbers'    => 'array',
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

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }
}

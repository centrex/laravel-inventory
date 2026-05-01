<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class PurchaseOrderItem extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;

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
        'purchase_order_id', 'product_id', 'variant_id',
        'qty_ordered', 'qty_received',
        'unit_price_local', 'unit_price_amount',
        'line_total_local', 'line_total_amount',
        'notes',
    ];

    protected $casts = [
        'qty_ordered'       => 'decimal:4',
        'qty_received'      => 'decimal:4',
        'unit_price_local'  => 'decimal:4',
        'unit_price_amount' => 'decimal:4',
        'line_total_local'  => 'decimal:4',
        'line_total_amount' => 'decimal:4',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
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

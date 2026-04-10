<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Centrex\Inventory\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class PurchaseOrder extends Model
{
    use AddTablePrefix;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'purchase_orders';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'po_number', 'warehouse_id', 'supplier_id',
        'currency', 'exchange_rate_bdt',
        'subtotal_local', 'subtotal_bdt',
        'tax_local', 'tax_bdt',
        'shipping_local', 'shipping_bdt',
        'other_charges_bdt',
        'total_local', 'total_bdt',
        'status', 'ordered_at', 'expected_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'status'             => PurchaseOrderStatus::class,
        'exchange_rate_bdt'  => 'decimal:8',
        'subtotal_local'     => 'decimal:4',
        'subtotal_bdt'       => 'decimal:4',
        'tax_local'          => 'decimal:4',
        'tax_bdt'            => 'decimal:4',
        'shipping_local'     => 'decimal:4',
        'shipping_bdt'       => 'decimal:4',
        'other_charges_bdt'  => 'decimal:4',
        'total_local'        => 'decimal:4',
        'total_bdt'          => 'decimal:4',
        'ordered_at'         => 'datetime',
        'expected_at'        => 'date',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function stockReceipts(): HasMany
    {
        return $this->hasMany(StockReceipt::class);
    }

    public function isFullyReceived(): bool
    {
        return $this->items->every(
            fn (PurchaseOrderItem $item) => (float) $item->qty_received >= (float) $item->qty_ordered
                - (float) config('inventory.qty_tolerance', 0.0001)
        );
    }
}

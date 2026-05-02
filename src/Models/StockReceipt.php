<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\{AddTablePrefix, HasTenant};
use Centrex\Inventory\Enums\StockReceiptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class StockReceipt extends Model implements Auditable
{
    use AddTablePrefix;
    use HasTenant;
    use AuditableTrait;

    protected function getTableSuffix(): string
    {
        return 'stock_receipts';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'grn_number', 'purchase_order_id', 'warehouse_id',
        'received_at', 'notes', 'status', 'created_by', 'accounting_journal_entry_id',
    ];

    protected $casts = [
        'status'      => StockReceiptStatus::class,
        'received_at' => 'datetime',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockReceiptItem::class);
    }

    public function isPosted(): bool
    {
        return $this->status === StockReceiptStatus::POSTED;
    }
}

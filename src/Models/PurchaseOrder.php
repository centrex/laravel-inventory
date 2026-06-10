<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Centrex\Inventory\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Purchase order (or requisition) header record.
 *
 * document_type: 'order' is a live PO sent to a supplier; 'requisition' is an internal
 * request that can be converted to a PO via
 * {@see \Centrex\Inventory\Inventory::createPurchaseOrderFromRequisition()}.
 *
 * Amounts follow the same dual-currency pattern as {@see SaleOrder}:
 *  - *_local  — in the PO's currency (e.g. USD)
 *  - *_amount — in the base currency (e.g. BDT), computed as local × exchange_rate
 *
 * @property int $id
 * @property string $po_number
 * @property string $document_type 'order' | 'requisition'
 * @property int $warehouse_id
 * @property int $supplier_id
 * @property string $currency ISO 4217 code
 * @property float $exchange_rate
 * @property float $subtotal_local
 * @property float $subtotal_amount
 * @property float $tax_local
 * @property float $tax_amount
 * @property float $discount_local
 * @property float $discount_amount
 * @property float $shipping_local
 * @property float $shipping_amount
 * @property float $other_charges_amount
 * @property float $total_local
 * @property float $total_amount
 * @property PurchaseOrderStatus $status
 * @property \Carbon\Carbon|null $ordered_at
 * @property \Carbon\Carbon|null $expected_at
 * @property string|null $notes
 * @property int|null $created_by
 * @property int|null $accounting_bill_id FK to laravel-accounting Bill
 */
class PurchaseOrder extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;
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
        'po_number', 'document_type', 'warehouse_id', 'supplier_id',
        'currency', 'exchange_rate',
        'subtotal_local', 'subtotal_amount',
        'tax_local', 'tax_amount',
        'discount_local', 'discount_amount',
        'shipping_local', 'shipping_amount',
        'other_charges_amount',
        'total_local', 'total_amount',
        'status', 'ordered_at', 'expected_at', 'notes', 'created_by',
        'purchase_manager_id', 'purchase_assistant_manager_id', 'purchase_executive_id',
        'accounting_bill_id',
    ];

    protected $casts = [
        'document_type'        => 'string',
        'status'               => PurchaseOrderStatus::class,
        'exchange_rate'        => 'decimal:8',
        'subtotal_local'       => 'decimal:4',
        'subtotal_amount'      => 'decimal:4',
        'tax_local'            => 'decimal:4',
        'tax_amount'           => 'decimal:4',
        'discount_local'       => 'decimal:4',
        'discount_amount'      => 'decimal:4',
        'shipping_local'       => 'decimal:4',
        'shipping_amount'      => 'decimal:4',
        'other_charges_amount' => 'decimal:4',
        'total_local'          => 'decimal:4',
        'total_amount'         => 'decimal:4',
        'ordered_at'           => 'datetime',
        'expected_at'          => 'date',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseManager(): BelongsTo
    {
        return $this->belongsTo((string) config('auth.providers.users.model', 'App\\Models\\User'), 'purchase_manager_id');
    }

    public function purchaseAssistantManager(): BelongsTo
    {
        return $this->belongsTo((string) config('auth.providers.users.model', 'App\\Models\\User'), 'purchase_assistant_manager_id');
    }

    public function purchaseExecutive(): BelongsTo
    {
        return $this->belongsTo((string) config('auth.providers.users.model', 'App\\Models\\User'), 'purchase_executive_id');
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
                - (float) config('inventory.qty_tolerance', 0.0001),
        );
    }
}

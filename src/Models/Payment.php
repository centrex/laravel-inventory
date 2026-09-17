<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Read-model mirror of laravel-accounting's Payment (invoice + bill payments), kept in sync
 * by PaymentMirrorObserver. Not a source of truth — payments are always created by calling
 * Accounting::recordInvoicePayment()/recordBillPayment(); this table exists so inventory-side
 * reporting/UI doesn't need a hard runtime dependency on laravel-accounting's own tables.
 */
class Payment extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'payments';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'accounting_payment_id', 'direction', 'payable_type', 'payable_id',
        'sale_order_id', 'purchase_order_id', 'customer_id', 'supplier_id', 'warehouse_id',
        'payment_number', 'payment_date', 'amount', 'currency', 'payment_method',
        'reference', 'notes', 'meta',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount'       => 'decimal:4',
        'meta'         => 'array',
    ];

    public function saleOrder(): BelongsTo
    {
        return $this->belongsTo(SaleOrder::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}

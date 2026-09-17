<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Read-model mirror of laravel-accounting's Expense (invoice/bill charges & discounts, and
 * standalone expenses), kept in sync by ExpenseMirrorObserver. Not a source of truth — records
 * are always created by calling Accounting::postExpense() (or accounting's own charge/discount
 * flows); this table exists so inventory-side reporting/UI doesn't need a hard runtime
 * dependency on laravel-accounting's own tables.
 */
class Expense extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'expenses';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'accounting_expense_id', 'direction', 'chargeable_type', 'chargeable_id',
        'sale_order_id', 'purchase_order_id', 'customer_id', 'supplier_id', 'warehouse_id',
        'expense_number', 'accounting_account_id', 'account_code', 'account_name',
        'expense_date', 'due_date', 'subtotal', 'tax_amount', 'total', 'paid_amount',
        'currency', 'exchange_rate', 'status', 'payment_method', 'reference',
        'vendor_name', 'notes', 'meta',
    ];

    protected $casts = [
        'expense_date'  => 'date',
        'due_date'      => 'date',
        'subtotal'      => 'decimal:4',
        'tax_amount'    => 'decimal:4',
        'total'         => 'decimal:4',
        'paid_amount'   => 'decimal:4',
        'exchange_rate' => 'decimal:8',
        'meta'          => 'array',
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

    public function getBalanceAttribute(): float
    {
        return (float) $this->total - (float) $this->paid_amount;
    }
}

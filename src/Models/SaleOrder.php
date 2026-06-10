<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Centrex\Inventory\Enums\{PriceTierCode, SaleOrderStatus};
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Sale order (or quotation) header record.
 *
 * document_type distinguishes between 'order' (live SO) and 'quotation' (draft that
 * can be converted to an order via {@see \Centrex\Inventory\Inventory::createSaleOrderFromQuotation()}).
 *
 * Monetary amounts are stored in two currencies:
 *  - *_local  — in the order's own currency (e.g. USD)
 *  - *_amount — in the base currency (e.g. BDT), computed as local × exchange_rate
 *
 * Credit-limit fields are snapshotted at order time so reports reflect the limit that was
 * in effect when the order was placed, not the current limit.
 *
 * @property int                $id
 * @property string             $so_number
 * @property string             $document_type          'order' | 'quotation'
 * @property int                $warehouse_id
 * @property int                $customer_id
 * @property int|null           $coupon_id
 * @property string             $price_tier_code        {@see PriceTierCode}
 * @property string             $currency               ISO 4217 code
 * @property float              $exchange_rate           Rate at time of order
 * @property float              $subtotal_local
 * @property float              $subtotal_amount
 * @property float              $tax_local
 * @property float              $tax_amount
 * @property float              $discount_local          Manual line-level discount
 * @property float              $discount_amount
 * @property float              $shipping_local
 * @property float              $shipping_amount
 * @property float              $coupon_discount_local
 * @property float              $coupon_discount_amount
 * @property float              $total_local
 * @property float              $total_amount
 * @property float              $paid_amount
 * @property float              $due_amount
 * @property float              $credit_limit_amount     Snapshotted at order time
 * @property float              $credit_exposure_before_amount
 * @property float              $credit_exposure_after_amount
 * @property bool               $credit_override_required
 * @property int|null           $credit_override_approved_by
 * @property \Carbon\Carbon|null $credit_override_approved_at
 * @property string|null        $credit_override_notes
 * @property float              $cogs_amount             Filled when the order is fulfilled
 * @property \Centrex\Inventory\Enums\SaleOrderStatus $status
 * @property \Carbon\Carbon|null $ordered_at
 * @property string|null        $notes
 * @property int|null           $created_by
 * @property int|null           $accounting_invoice_id   FK to laravel-accounting Invoice
 */
class SaleOrder extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'sale_orders';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'so_number', 'document_type', 'warehouse_id', 'customer_id', 'coupon_id', 'price_tier_code',
        'coupon_code', 'coupon_name', 'coupon_discount_type', 'coupon_discount_value',
        'currency', 'exchange_rate',
        'subtotal_local', 'subtotal_amount',
        'tax_local', 'tax_amount',
        'discount_local', 'discount_amount',
        'shipping_local', 'shipping_amount',
        'coupon_discount_local', 'coupon_discount_amount',
        'total_local', 'total_amount', 'paid_amount', 'due_amount', 'credit_limit_amount',
        'credit_exposure_before_amount', 'credit_exposure_after_amount',
        'credit_override_required', 'credit_override_approved_by',
        'credit_override_approved_at', 'credit_override_notes',
        'cogs_amount', 'status', 'ordered_at', 'notes', 'created_by',
        'sales_manager_id', 'sales_assistant_manager_id', 'sales_executive_id',
        'accounting_invoice_id',
    ];

    protected $casts = [
        'document_type'                 => 'string',
        'status'                        => SaleOrderStatus::class,
        'exchange_rate'                 => 'decimal:8',
        'subtotal_local'                => 'decimal:4',
        'subtotal_amount'               => 'decimal:4',
        'tax_local'                     => 'decimal:4',
        'tax_amount'                    => 'decimal:4',
        'discount_local'                => 'decimal:4',
        'discount_amount'               => 'decimal:4',
        'shipping_local'                => 'decimal:4',
        'shipping_amount'               => 'decimal:4',
        'coupon_discount_value'         => 'decimal:4',
        'coupon_discount_local'         => 'decimal:4',
        'coupon_discount_amount'        => 'decimal:4',
        'total_local'                   => 'decimal:4',
        'total_amount'                  => 'decimal:4',
        'paid_amount'                   => 'decimal:4',
        'due_amount'                    => 'decimal:4',
        'credit_limit_amount'           => 'decimal:4',
        'credit_exposure_before_amount' => 'decimal:4',
        'credit_exposure_after_amount'  => 'decimal:4',
        'credit_override_required'      => 'boolean',
        'credit_override_approved_at'   => 'datetime',
        'cogs_amount'                   => 'decimal:4',
        'ordered_at'                    => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo((string) config('auth.providers.users.model', 'App\\Models\\User'), 'created_by');
    }

    public function salesManager(): BelongsTo
    {
        return $this->belongsTo((string) config('auth.providers.users.model', 'App\\Models\\User'), 'sales_manager_id');
    }

    public function salesAssistantManager(): BelongsTo
    {
        return $this->belongsTo((string) config('auth.providers.users.model', 'App\\Models\\User'), 'sales_assistant_manager_id');
    }

    public function salesExecutive(): BelongsTo
    {
        return $this->belongsTo((string) config('auth.providers.users.model', 'App\\Models\\User'), 'sales_executive_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleOrderItem::class);
    }

    public function getPriceTierNameAttribute(): ?string
    {
        return PriceTierCode::labelFor($this->price_tier_code);
    }

    public function grossProfitAmount(): float
    {
        return (float) $this->total_amount - (float) $this->cogs_amount;
    }

    public function grossProfitBdt(): float
    {
        return $this->grossProfitAmount();
    }

    public function grossMarginPct(): float
    {
        if ((float) $this->total_amount == 0.0) {
            return 0.0;
        }

        return round($this->grossProfitAmount() / (float) $this->total_amount * 100, 2);
    }
}

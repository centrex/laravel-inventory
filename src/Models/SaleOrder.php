<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\{AddTablePrefix};
use Centrex\Inventory\Enums\{PriceTierCode, SaleOrderStatus};
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

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
        'total_local', 'total_amount', 'credit_limit_amount',
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

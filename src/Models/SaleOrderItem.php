<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Centrex\Inventory\Enums\PriceTierCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleOrderItem extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'sale_order_items';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'sale_order_id', 'product_id', 'variant_id', 'price_tier_code',
        'qty_ordered', 'qty_fulfilled',
        'unit_price_local', 'unit_price_amount', 'unit_cost_amount',
        'discount_pct', 'line_total_local', 'line_total_amount',
        'notes',
    ];

    protected $casts = [
        'qty_ordered'       => 'decimal:4',
        'qty_fulfilled'     => 'decimal:4',
        'unit_price_local'  => 'decimal:4',
        'unit_price_amount' => 'decimal:4',
        'unit_cost_amount'  => 'decimal:4',
        'discount_pct'      => 'decimal:2',
        'line_total_local'  => 'decimal:4',
        'line_total_amount' => 'decimal:4',
    ];

    public function saleOrder(): BelongsTo
    {
        return $this->belongsTo(SaleOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function lineCogsAmount(): float
    {
        return round((float) $this->qty_fulfilled * (float) $this->unit_cost_amount, 4);
    }

    public function lineCogsBdt(): float
    {
        return $this->lineCogsAmount();
    }

    public function getPriceTierNameAttribute(): ?string
    {
        return PriceTierCode::labelFor($this->price_tier_code);
    }
}

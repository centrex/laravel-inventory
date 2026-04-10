<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo};

class ProductPrice extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'product_prices';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'product_id', 'price_tier_id', 'warehouse_id',
        'price_bdt', 'price_local', 'currency',
        'effective_from', 'effective_to', 'is_active',
    ];

    protected $casts = [
        'price_bdt'      => 'decimal:4',
        'price_local'    => 'decimal:4',
        'effective_from' => 'date',
        'effective_to'   => 'date',
        'is_active'      => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function priceTier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function isGlobal(): bool
    {
        return $this->warehouse_id === null;
    }

    public function isEffective(?string $date = null): bool
    {
        $date = $date ? now()->parse($date) : now();

        if ($this->effective_from && $date->lt($this->effective_from)) {
            return false;
        }

        if ($this->effective_to && $date->gt($this->effective_to)) {
            return false;
        }

        return true;
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Stock ledger row — one row per (warehouse, product, variant) combination.
 *
 * All quantity columns use 4 decimal places to support fractional units (e.g. kg, litres).
 * Available quantity = qty_on_hand − qty_reserved.
 *
 * @property int $id
 * @property int $warehouse_id
 * @property int $product_id
 * @property int|null $variant_id
 * @property float $qty_on_hand Physically present stock
 * @property float $qty_reserved Committed to open sale orders (not yet picked)
 * @property float $qty_in_transit Dispatched from another warehouse, not yet received here
 * @property float $qty_damaged Damage-bin stock (tracked separately from on-hand)
 * @property float $wac_amount Weighted-average cost per unit in base currency
 * @property float|null $reorder_point Trigger level for low-stock alerts
 * @property float|null $reorder_qty Suggested order quantity when restocking
 * @property string|null $bin_location Physical bin / shelf reference
 */
class WarehouseProduct extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;

    protected function getTableSuffix(): string
    {
        return 'warehouse_products';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'warehouse_id', 'product_id', 'variant_id',
        'qty_on_hand', 'qty_reserved', 'qty_in_transit', 'qty_damaged',
        'wac_amount', 'reorder_point', 'reorder_qty', 'bin_location',
    ];

    protected $casts = [
        'qty_on_hand'    => 'decimal:4',
        'qty_reserved'   => 'decimal:4',
        'qty_in_transit' => 'decimal:4',
        'qty_damaged'    => 'decimal:4',
        'wac_amount'     => 'decimal:4',
        'reorder_point'  => 'decimal:4',
        'reorder_qty'    => 'decimal:4',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function getSkuAttribute(): ?string
    {
        if ($this->relationLoaded('variant') && $this->variant !== null) {
            return $this->variant->sku;
        }

        if ($this->relationLoaded('product')) {
            return $this->product?->sku;
        }

        return null;
    }

    public function qtyAvailable(): float
    {
        return (float) $this->qty_on_hand - (float) $this->qty_reserved;
    }

    public function qtyDamagedAvailable(): float
    {
        return max(0.0, (float) $this->qty_damaged);
    }

    public function totalValue(): float
    {
        return round((float) $this->qty_on_hand * (float) $this->wac_amount, (int) config('inventory.wac_precision', 4));
    }

    public function isLowStock(): bool
    {
        if ($this->reorder_point === null) {
            return false;
        }

        return $this->qtyAvailable() <= (float) $this->reorder_point;
    }
}

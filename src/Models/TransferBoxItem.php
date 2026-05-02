<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class TransferBoxItem extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;

    protected function getTableSuffix(): string
    {
        return 'shipment_box_items';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'shipment_box_id',
        'product_id',
        'variant_id',
        'qty_sent',
        'theoretical_weight_kg',
        'allocated_weight_kg',
        'weight_ratio',
        'source_unit_cost_amount',
        'shipping_allocated_amount',
        'unit_landed_cost_amount',
        'notes',
    ];

    protected $casts = [
        'qty_sent'                  => 'decimal:4',
        'theoretical_weight_kg'     => 'decimal:4',
        'allocated_weight_kg'       => 'decimal:4',
        'weight_ratio'              => 'decimal:8',
        'source_unit_cost_amount'   => 'decimal:4',
        'shipping_allocated_amount' => 'decimal:4',
        'unit_landed_cost_amount'   => 'decimal:4',
    ];

    public function box(): BelongsTo
    {
        return $this->belongsTo(TransferBox::class, 'shipment_box_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}

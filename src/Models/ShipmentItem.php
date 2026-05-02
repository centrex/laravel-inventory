<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class ShipmentItem extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;

    protected function getTableSuffix(): string
    {
        return 'shipment_items';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'shipment_id', 'product_id', 'variant_id',
        'qty_sent', 'qty_received',
        'unit_cost_source_amount', 'weight_kg_total',
        'shipping_allocated_amount', 'unit_landed_cost_amount',
        'wac_source_before_amount', 'wac_dest_before_amount', 'wac_dest_after_amount',
        'notes',
    ];

    protected $casts = [
        'qty_sent'                  => 'decimal:4',
        'qty_received'              => 'decimal:4',
        'unit_cost_source_amount'   => 'decimal:4',
        'weight_kg_total'           => 'decimal:4',
        'shipping_allocated_amount' => 'decimal:4',
        'unit_landed_cost_amount'   => 'decimal:4',
        'wac_source_before_amount'  => 'decimal:4',
        'wac_dest_before_amount'    => 'decimal:4',
        'wac_dest_after_amount'     => 'decimal:4',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
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

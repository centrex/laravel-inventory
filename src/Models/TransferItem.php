<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferItem extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'transfer_items';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'transfer_id', 'product_id',
        'qty_sent', 'qty_received',
        'unit_cost_source_bdt', 'weight_kg_total',
        'shipping_allocated_bdt', 'unit_landed_cost_bdt',
        'wac_source_before_bdt', 'wac_dest_before_bdt', 'wac_dest_after_bdt',
        'notes',
    ];

    protected $casts = [
        'qty_sent'                => 'decimal:4',
        'qty_received'            => 'decimal:4',
        'unit_cost_source_bdt'    => 'decimal:4',
        'weight_kg_total'         => 'decimal:4',
        'shipping_allocated_bdt'  => 'decimal:4',
        'unit_landed_cost_bdt'    => 'decimal:4',
        'wac_source_before_bdt'   => 'decimal:4',
        'wac_dest_before_bdt'     => 'decimal:4',
        'wac_dest_after_bdt'      => 'decimal:4',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

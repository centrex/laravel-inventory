<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\{AddTablePrefix, HasPrimaryImage};
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;

class Warehouse extends Model implements HasMedia
{
    use AddTablePrefix;
    use HasPrimaryImage;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'warehouses';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'code', 'name', 'country_code', 'currency',
        'address', 'is_active', 'is_default', 'meta',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'is_default' => 'boolean',
        'meta'       => 'array',
    ];

    protected $appends = [
        'primary_image_url',
    ];

    public function warehouseProducts(): HasMany
    {
        return $this->hasMany(WarehouseProduct::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function stockReceipts(): HasMany
    {
        return $this->hasMany(StockReceipt::class);
    }

    public function saleOrders(): HasMany
    {
        return $this->hasMany(SaleOrder::class);
    }

    public function outboundTransfers(): HasMany
    {
        return $this->hasMany(Transfer::class, 'from_warehouse_id');
    }

    public function inboundTransfers(): HasMany
    {
        return $this->hasMany(Transfer::class, 'to_warehouse_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(Adjustment::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}

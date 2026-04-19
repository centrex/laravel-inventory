<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class TransferBox extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'transfer_boxes';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'transfer_id',
        'box_code',
        'measured_weight_kg',
        'notes',
    ];

    protected $casts = [
        'measured_weight_kg' => 'decimal:4',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransferBoxItem::class);
    }
}

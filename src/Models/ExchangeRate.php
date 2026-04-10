<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'exchange_rates';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = ['currency', 'rate_bdt', 'source', 'valid_at'];

    protected $casts = [
        'rate_bdt' => 'decimal:8',
        'valid_at' => 'date',
    ];

    public function convertToBdt(float $amount): float
    {
        return round($amount * (float) $this->rate_bdt, (int) config('inventory.wac_precision', 4));
    }

    public function convertFromBdt(float $amountBdt): float
    {
        if ((float) $this->rate_bdt == 0.0) {
            return 0.0;
        }

        return round($amountBdt / (float) $this->rate_bdt, (int) config('inventory.wac_precision', 4));
    }
}

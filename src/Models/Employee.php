<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};

class Employee extends Model
{
    use AddTablePrefix;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'employees';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'code', 'name', 'email', 'phone', 'address',
        'city', 'country', 'tax_id', 'currency',
        'credit_limit', 'payment_terms', 'is_active',
        'modelable_type', 'modelable_id',
    ];

    protected $casts = [
        'credit_limit'  => 'decimal:2',
        'payment_terms' => 'integer',
        'is_active'     => 'boolean',
    ];
}

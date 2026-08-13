<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Illuminate\Database\Eloquent\Relations\{BelongsTo, Pivot};

class AgentCustomer extends Pivot
{
    public $incrementing = true;

    protected $fillable = [
        'agent_id', 'customer_id', 'territory', 'assigned_at', 'is_primary', 'notes',
    ];

    protected $casts = [
        'is_primary'  => 'boolean',
        'assigned_at' => 'date',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $prefix = config('inventory.table_prefix', 'inv_') ?: 'inv_';
        $this->setTable($prefix . 'agent_customers');
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}

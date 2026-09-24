<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, Pivot};

class AgentCustomer extends Pivot
{
    use AddTablePrefix;

    public $incrementing = true;

    protected $fillable = [
        'agent_id', 'customer_id', 'territory', 'assigned_at', 'is_primary', 'notes',
    ];

    protected $casts = [
        'is_primary'  => 'boolean',
        'assigned_at' => 'date',
    ];

    protected function getTableSuffix(): string
    {
        return 'agent_customers';
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

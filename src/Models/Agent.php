<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, BelongsToMany, HasMany};

class Agent extends Model
{
    use AddTablePrefix;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'agents';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'code', 'name', 'email', 'phone', 'zone', 'area',
        'price_tier_code', 'commission_rate_pct', 'customer_id',
        'is_active', 'notes',
    ];

    protected $casts = [
        'commission_rate_pct' => 'decimal:4',
        'is_active'           => 'boolean',
    ];

    /** The agent's own inv_customers record — used as customer_id on B2B sale orders. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** Customers assigned to this agent via the pivot table (many-to-many). */
    public function customers(): BelongsToMany
    {
        $prefix = config('inventory.table_prefix', 'inv_') ?: 'inv_';

        return $this->belongsToMany(Customer::class, $prefix . 'agent_customers', 'agent_id', 'customer_id')
            ->using(AgentCustomer::class)
            ->withPivot(['territory', 'assigned_at', 'is_primary', 'notes'])
            ->withTimestamps();
    }

    /** B2B sale orders placed for this agent. */
    public function b2bOrders(): HasMany
    {
        return $this->hasMany(SaleOrder::class, 'agent_customer_id');
    }
}

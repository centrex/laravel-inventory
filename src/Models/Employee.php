<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'city', 'country', 'department', 'designation',
        'employment_type', 'joining_date', 'monthly_salary',
        'bank_account_name', 'bank_account_number',
        'emergency_contact_name', 'emergency_contact_phone',
        'tax_id', 'currency', 'credit_limit', 'payment_terms', 'is_active',
        'modelable_type', 'modelable_id',
    ];

    protected $casts = [
        'joining_date'   => 'date',
        'monthly_salary' => 'decimal:2',
        'credit_limit'   => 'decimal:2',
        'payment_terms'  => 'integer',
        'is_active'      => 'boolean',
    ];

    public function payrollLines(): HasMany
    {
        return $this->hasMany(PayrollEntryLine::class);
    }
}

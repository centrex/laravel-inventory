<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollEntryLine extends Model
{
    use AddTablePrefix;

    protected function getTableSuffix(): string
    {
        return 'payroll_entry_lines';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'payroll_entry_id', 'payroll_account_id', 'amount', 'description', 'reference',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function payrollEntry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class);
    }

    public function payrollAccount(): BelongsTo
    {
        return $this->belongsTo(PayrollAccount::class);
    }
}

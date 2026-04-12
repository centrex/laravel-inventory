<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class PayrollEntry extends Model
{
    use AddTablePrefix;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'payroll_entries';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'entry_number', 'date', 'reference', 'description',
        'currency', 'type', 'exchange_rate',
        'created_by', 'approved_by', 'approved_at', 'status',
    ];

    protected $casts = [
        'date'          => 'date',
        'approved_at'   => 'datetime',
        'exchange_rate' => 'decimal:6',
    ];

    #[\Override]
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $entry): void {
            if ($entry->entry_number) {
                return;
            }

            DB::connection($entry->getConnectionName())->transaction(function () use ($entry): void {
                $date = now()->format('Ymd');

                $lastEntry = self::query()
                    ->whereDate('created_at', now()->toDateString())
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                $sequence = 1;

                if ($lastEntry && preg_match('/(\d+)$/', $lastEntry->entry_number, $matches)) {
                    $sequence = ((int) $matches[1]) + 1;
                }

                $entry->entry_number = sprintf('PAY-%s-%05d', $date, $sequence);
            });
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollEntryLine::class);
    }

    public function getTotalAmountAttribute(): float
    {
        return (float) $this->lines()->sum('amount');
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Expense extends Model
{
    use AddTablePrefix;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'expenses';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'expense_number', 'account_id', 'expense_date', 'due_date',
        'subtotal', 'tax_amount', 'total', 'paid_amount',
        'currency', 'exchange_rate', 'status', 'notes', 'journal_entry_id',
        'payment_method', 'reference', 'vendor_name',
    ];

    protected $casts = [
        'expense_date'  => 'date',
        'due_date'      => 'date',
        'status'        => 'string',
        'subtotal'      => 'decimal:2',
        'tax_amount'    => 'decimal:2',
        'total'         => 'decimal:2',
        'paid_amount'   => 'decimal:2',
        'exchange_rate' => 'decimal:6',
    ];

    #[\Override]
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $expense): void {
            if ($expense->expense_number) {
                return;
            }

            DB::connection($expense->getConnectionName())->transaction(function () use ($expense): void {
                $date = now()->format('Ymd');

                $lastExpense = self::query()
                    ->whereDate('created_at', now()->toDateString())
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                $sequence = 1;

                if ($lastExpense && preg_match('/(\d+)$/', $lastExpense->expense_number, $m)) {
                    $sequence = ((int) $m[1]) + 1;
                }

                $expense->expense_number = sprintf('EXP-%s-%05d', $date, $sequence);
            });
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExpenseItem::class);
    }

    public function getBalanceAttribute(): float
    {
        return (float) $this->total - (float) $this->paid_amount;
    }

    public function getIsPaidAttribute(): bool
    {
        return (float) $this->paid_amount >= (float) $this->total;
    }
}

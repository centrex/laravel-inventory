<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $connection = config('inventory.drivers.database.connection', config('database.default'));
        $schema = Schema::connection($connection);

        $table = $prefix . 'stock_receipts';

        if (!$schema->hasColumn($table, 'bill_offset_amount')) {
            $schema->table($table, function (Blueprint $table): void {
                $table->decimal('bill_offset_amount', 18, 4)->default(0)->after('accounting_journal_entry_id');
            });
        }
    }

    public function down(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $connection = config('inventory.drivers.database.connection', config('database.default'));
        $schema = Schema::connection($connection);

        $table = $prefix . 'stock_receipts';

        if ($schema->hasColumn($table, 'bill_offset_amount')) {
            $schema->table($table, function (Blueprint $table): void {
                $table->dropColumn('bill_offset_amount');
            });
        }
    }
};

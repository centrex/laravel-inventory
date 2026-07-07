<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $conn = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($conn)->table($prefix . 'sale_returns', function (Blueprint $table): void {
            $table->unsignedBigInteger('accounting_journal_entry_id')->nullable();
            $table->index('accounting_journal_entry_id');
        });

        Schema::connection($conn)->table($prefix . 'purchase_returns', function (Blueprint $table): void {
            $table->unsignedBigInteger('accounting_journal_entry_id')->nullable();
            $table->index('accounting_journal_entry_id');
        });
    }

    public function down(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $conn = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($conn)->table($prefix . 'sale_returns', function (Blueprint $table): void {
            $table->dropColumn('accounting_journal_entry_id');
        });

        Schema::connection($conn)->table($prefix . 'purchase_returns', function (Blueprint $table): void {
            $table->dropColumn('accounting_journal_entry_id');
        });
    }
};

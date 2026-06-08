<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class() extends Migration
{
    public function up(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $table = $prefix . 'sale_orders';

        Schema::table($table, function (Blueprint $table): void {
            $table->decimal('paid_amount', 18, 4)->default(0)->after('total_amount');
            $table->decimal('due_amount', 18, 4)->default(0)->after('paid_amount');
        });

        // Initialise existing rows: due = total (no payment info yet)
        DB::statement("UPDATE `{$table}` SET due_amount = total_amount WHERE due_amount = 0");
    }

    public function down(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');

        Schema::table($prefix . 'sale_orders', function (Blueprint $table): void {
            $table->dropColumn(['paid_amount', 'due_amount']);
        });
    }
};

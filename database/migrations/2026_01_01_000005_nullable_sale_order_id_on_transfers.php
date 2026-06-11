<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        $table = config('inventory.table_prefix', 'inv_') . 'transfers';

        Schema::table($table, function (Blueprint $table): void {
            $table->foreignId('sale_order_id')->nullable()->change();
            $table->foreignId('warehouse_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        $table = config('inventory.table_prefix', 'inv_') . 'transfers';

        Schema::table($table, function (Blueprint $table): void {
            $table->foreignId('sale_order_id')->nullable(false)->change();
            $table->foreignId('warehouse_id')->nullable(false)->change();
        });
    }
};

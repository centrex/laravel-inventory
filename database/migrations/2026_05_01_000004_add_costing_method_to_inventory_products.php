<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        $p = config('inventory.table_prefix', 'inv_');
        $c = config('inventory.drivers.database.connection', config('database.default'));

        // wac = Weighted Average Cost (default), fifo = First-In First-Out, lifo = Last-In First-Out
        Schema::connection($c)->table($p . 'products', function (Blueprint $table): void {
            $table->string('costing_method', 10)->default('wac')->after('is_stockable');
        });
    }

    public function down(): void
    {
        $p = config('inventory.table_prefix', 'inv_');
        $c = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($c)->table($p . 'products', function (Blueprint $table): void {
            $table->dropColumn('costing_method');
        });
    }
};

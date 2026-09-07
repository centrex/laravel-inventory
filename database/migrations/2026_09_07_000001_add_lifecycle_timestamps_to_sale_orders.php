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
        $connection = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->table($prefix . 'sale_orders', function (Blueprint $table): void {
            $table->timestamp('confirmed_at')->nullable()->after('ordered_at');
            $table->timestamp('reserved_at')->nullable()->after('confirmed_at');
            $table->timestamp('shipped_at')->nullable()->after('reserved_at');
            $table->timestamp('fulfilled_at')->nullable()->after('shipped_at');
            $table->timestamp('completed_at')->nullable()->after('fulfilled_at');
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $connection = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->table($prefix . 'sale_orders', function (Blueprint $table): void {
            $table->dropColumn(['confirmed_at', 'reserved_at', 'shipped_at', 'fulfilled_at', 'completed_at', 'cancelled_at']);
        });
    }
};

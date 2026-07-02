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
        $table = $prefix . 'customers';
        $conn = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($conn)->table($table, function (Blueprint $table) use ($prefix): void {
            $table->boolean('is_agent')->default(false)->after('is_active');
            $table->unsignedBigInteger('agent_id')->nullable()->after('is_agent')->index();

            $table->foreign('agent_id')->references('id')->on($prefix . 'customers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $table = $prefix . 'customers';
        $conn = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($conn)->table($table, function (Blueprint $table): void {
            $table->dropForeign(['agent_id']);
            $table->dropColumn(['is_agent', 'agent_id']);
        });
    }
};

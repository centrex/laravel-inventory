<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $table  = $prefix . 'products';
        $conn   = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($conn)->table($table, function (Blueprint $table) use ($prefix): void {
            $table->string('slug', 200)->nullable()->unique()->after('name');
            $table->string('meta_title', 200)->nullable()->after('description');
            $table->string('meta_description', 500)->nullable()->after('meta_title');
        });
    }

    public function down(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $table  = $prefix . 'products';
        $conn   = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($conn)->table($table, function (Blueprint $table): void {
            $table->dropColumn(['slug', 'meta_title', 'meta_description']);
        });
    }
};

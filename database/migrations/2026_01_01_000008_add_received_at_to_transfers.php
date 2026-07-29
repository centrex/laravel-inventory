<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $c = config('inventory.drivers.database.connection', config('database.default'));
        $p = config('inventory.table_prefix', 'inv_');

        Schema::connection($c)->table($p . 'transfers', function (Blueprint $table): void {
            $table->timestamp('received_at')->nullable()->after('dispatched_at');
        });
    }

    public function down(): void
    {
        $c = config('inventory.drivers.database.connection', config('database.default'));
        $p = config('inventory.table_prefix', 'inv_');

        Schema::connection($c)->table($p . 'transfers', function (Blueprint $table): void {
            $table->dropColumn('received_at');
        });
    }
};

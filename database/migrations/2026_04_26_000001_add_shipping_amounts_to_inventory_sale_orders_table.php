<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('inventory.drivers.database.connection', config('database.default'));
        $table = config('inventory.table_prefix', 'inv_') . 'sale_orders';
        $schema = Schema::connection($connection);
        $needsShippingLocal = !$schema->hasColumn($table, 'shipping_local');
        $needsShippingAmount = !$schema->hasColumn($table, 'shipping_amount');

        if ($needsShippingLocal || $needsShippingAmount) {
            $schema->table($table, function (Blueprint $blueprint) use ($needsShippingLocal, $needsShippingAmount): void {
                if ($needsShippingLocal) {
                    $blueprint->decimal('shipping_local', 18, 4)->default(0)->after('discount_amount');
                }

                if ($needsShippingAmount) {
                    $blueprint->decimal('shipping_amount', 18, 4)->default(0)->after('shipping_local');
                }
            });
        }
    }

    public function down(): void
    {
        $connection = config('inventory.drivers.database.connection', config('database.default'));
        $table = config('inventory.table_prefix', 'inv_') . 'sale_orders';
        $schema = Schema::connection($connection);
        $hasShippingAmount = $schema->hasColumn($table, 'shipping_amount');
        $hasShippingLocal = $schema->hasColumn($table, 'shipping_local');

        if ($hasShippingAmount || $hasShippingLocal) {
            $schema->table($table, function (Blueprint $blueprint) use ($hasShippingAmount, $hasShippingLocal): void {
                if ($hasShippingAmount) {
                    $blueprint->dropColumn('shipping_amount');
                }

                if ($hasShippingLocal) {
                    $blueprint->dropColumn('shipping_local');
                }
            });
        }
    }
};

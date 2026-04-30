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

        // Track damaged units separately from good stock per warehouse-product
        Schema::connection($c)->table($p . 'warehouse_products', function (Blueprint $table): void {
            $table->decimal('qty_damaged', 18, 4)->default(0)->after('qty_in_transit');
        });

        // Allow a separate discounted price row for damaged-condition stock
        Schema::connection($c)->table($p . 'product_prices', function (Blueprint $table): void {
            $table->boolean('is_damaged')->default(false)->after('is_active');
            $table->index('is_damaged');
        });

        // Mark which sale order items were fulfilled from damaged stock
        Schema::connection($c)->table($p . 'sale_order_items', function (Blueprint $table): void {
            $table->boolean('from_damaged')->default(false)->after('lot_id');
        });
    }

    public function down(): void
    {
        $p = config('inventory.table_prefix', 'inv_');
        $c = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($c)->table($p . 'sale_order_items', function (Blueprint $table): void {
            $table->dropColumn('from_damaged');
        });

        Schema::connection($c)->table($p . 'product_prices', function (Blueprint $table): void {
            $table->dropIndex(['is_damaged']);
            $table->dropColumn('is_damaged');
        });

        Schema::connection($c)->table($p . 'warehouse_products', function (Blueprint $table): void {
            $table->dropColumn('qty_damaged');
        });
    }
};

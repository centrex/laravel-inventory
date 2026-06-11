<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        $c = config('inventory.drivers.database.connection', config('database.default'));
        $p = config('inventory.table_prefix', 'inv_');
        $table = $p . 'transfer_items';

        Schema::connection($c)->table($table, function (Blueprint $table): void {
            // sale_order_item_id is only relevant for SO-linked transfers
            $table->foreignId('sale_order_item_id')->nullable()->change();

            if (!Schema::hasColumn($table->getTable(), 'qty_sent')) {
                $table->decimal('qty_sent', 18, 4)->default(0)->after('lot_id');
            }

            if (!Schema::hasColumn($table->getTable(), 'qty_received')) {
                $table->decimal('qty_received', 18, 4)->default(0)->after('qty_sent');
            }

            if (!Schema::hasColumn($table->getTable(), 'unit_cost_source_amount')) {
                $table->decimal('unit_cost_source_amount', 18, 4)->default(0)->after('qty_received');
            }

            if (!Schema::hasColumn($table->getTable(), 'weight_kg_total')) {
                $table->decimal('weight_kg_total', 18, 4)->default(0)->after('unit_cost_source_amount');
            }

            if (!Schema::hasColumn($table->getTable(), 'shipping_allocated_amount')) {
                $table->decimal('shipping_allocated_amount', 18, 4)->default(0)->after('weight_kg_total');
            }

            if (!Schema::hasColumn($table->getTable(), 'unit_landed_cost_amount')) {
                $table->decimal('unit_landed_cost_amount', 18, 4)->default(0)->after('shipping_allocated_amount');
            }

            if (!Schema::hasColumn($table->getTable(), 'wac_source_before_amount')) {
                $table->decimal('wac_source_before_amount', 18, 4)->default(0)->after('unit_landed_cost_amount');
            }

            if (!Schema::hasColumn($table->getTable(), 'wac_dest_before_amount')) {
                $table->decimal('wac_dest_before_amount', 18, 4)->default(0)->after('wac_source_before_amount');
            }

            if (!Schema::hasColumn($table->getTable(), 'wac_dest_after_amount')) {
                $table->decimal('wac_dest_after_amount', 18, 4)->default(0)->after('wac_dest_before_amount');
            }
        });
    }

    public function down(): void
    {
        $c = config('inventory.drivers.database.connection', config('database.default'));
        $p = config('inventory.table_prefix', 'inv_');
        $table = $p . 'transfer_items';

        Schema::connection($c)->table($table, function (Blueprint $table): void {
            $table->foreignId('sale_order_item_id')->nullable(false)->change();
            $table->dropColumn([
                'qty_sent', 'qty_received', 'unit_cost_source_amount',
                'weight_kg_total', 'shipping_allocated_amount', 'unit_landed_cost_amount',
                'wac_source_before_amount', 'wac_dest_before_amount', 'wac_dest_after_amount',
            ]);
        });
    }
};

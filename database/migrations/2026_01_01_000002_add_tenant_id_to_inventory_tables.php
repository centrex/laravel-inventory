<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /** Root-aggregate tables that require per-tenant isolation. */
    private function rootTables(string $prefix): array
    {
        return [
            // Master data
            $prefix . 'warehouses',
            $prefix . 'product_categories',
            $prefix . 'product_brands',
            $prefix . 'products',
            $prefix . 'product_variants',
            $prefix . 'product_variant_attribute_types',
            $prefix . 'product_variant_attribute_values',
            $prefix . 'suppliers',
            $prefix . 'customers',
            $prefix . 'coupons',
            $prefix . 'commercial_team_members',
            $prefix . 'product_prices',
            $prefix . 'warehouse_products',
            $prefix . 'lots',
            $prefix . 'serial_numbers',
            // Transactions
            $prefix . 'purchase_orders',
            $prefix . 'stock_receipts',
            $prefix . 'sale_orders',
            $prefix . 'transfers',
            $prefix . 'shipments',
            $prefix . 'sale_returns',
            $prefix . 'purchase_returns',
            $prefix . 'adjustments',
            $prefix . 'stock_movements',
            $prefix . 'pick_lists',
            // Partners & analytics
            $prefix . 'partners',
            $prefix . 'product_trend_snapshots',
            $prefix . 'customer_product_stats',
            $prefix . 'supplier_product_stats',
        ];
    }

    public function up(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $connection = config('inventory.drivers.database.connection', config('database.default'));

        foreach ($this->rootTables($prefix) as $table) {
            Schema::connection($connection)->table($table, function (Blueprint $t): void {
                $t->unsignedBigInteger('tenant_id')->nullable()->after('id')->index();
            });
        }
    }

    public function down(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $connection = config('inventory.drivers.database.connection', config('database.default'));

        foreach ($this->rootTables($prefix) as $table) {
            Schema::connection($connection)->table($table, function (Blueprint $t): void {
                $t->dropColumn('tenant_id');
            });
        }
    }
};

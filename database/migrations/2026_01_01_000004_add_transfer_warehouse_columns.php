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

        Schema::table($p . 'transfers', function (Blueprint $table) use ($p): void {
            if (!Schema::hasColumn($p . 'transfers', 'from_warehouse_id')) {
                $table->foreignId('from_warehouse_id')->nullable()->constrained($p . 'warehouses')->restrictOnDelete();
            }

            if (!Schema::hasColumn($p . 'transfers', 'to_warehouse_id')) {
                $table->foreignId('to_warehouse_id')->nullable()->constrained($p . 'warehouses')->restrictOnDelete();
            }

            if (!Schema::hasColumn($p . 'transfers', 'shipping_rate_per_kg')) {
                $table->decimal('shipping_rate_per_kg', 18, 4)->default(0)->after('to_warehouse_id');
            }

            if (!Schema::hasColumn($p . 'transfers', 'total_weight_kg')) {
                $table->decimal('total_weight_kg', 18, 4)->default(0)->after('shipping_rate_per_kg');
            }

            if (!Schema::hasColumn($p . 'transfers', 'shipping_cost_amount')) {
                $table->decimal('shipping_cost_amount', 18, 4)->default(0)->after('total_weight_kg');
            }

            if (!Schema::hasColumn($p . 'transfers', 'shipped_at')) {
                $table->timestamp('shipped_at')->nullable()->after('dispatched_at');
            }
        });

        Schema::table($p . 'transfers', function (Blueprint $table) use ($p): void {
            if (!$this->indexExists($p . 'transfers', $p . 'transfers_from_warehouse_id_status_index')) {
                $table->index(['from_warehouse_id', 'status']);
            }

            if (!$this->indexExists($p . 'transfers', $p . 'transfers_to_warehouse_id_status_index')) {
                $table->index(['to_warehouse_id', 'status']);
            }
        });
    }

    public function down(): void
    {
        $p = config('inventory.table_prefix', 'inv_');

        Schema::table($p . 'transfers', function (Blueprint $table) use ($p): void {
            $table->dropForeign([$p . 'transfers_from_warehouse_id_foreign']);
            $table->dropForeign([$p . 'transfers_to_warehouse_id_foreign']);
            $table->dropColumn(['from_warehouse_id', 'to_warehouse_id', 'shipping_rate_per_kg', 'total_weight_kg', 'shipping_cost_amount', 'shipped_at']);
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(DB::select("SHOW INDEX FROM `{$table}`"))
            ->pluck('Key_name')
            ->contains($index);
    }
};

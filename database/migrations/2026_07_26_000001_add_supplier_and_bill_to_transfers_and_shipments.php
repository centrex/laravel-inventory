<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $p = config('inventory.table_prefix', 'inv_');
        $conn = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($conn)->table($p . 'transfers', function (Blueprint $table) use ($p): void {
            if (!Schema::hasColumn($p . 'transfers', 'supplier_id')) {
                $table->foreignId('supplier_id')->nullable()->after('to_warehouse_id')->constrained($p . 'suppliers')->nullOnDelete();
            }

            if (!Schema::hasColumn($p . 'transfers', 'accounting_bill_id')) {
                $table->unsignedBigInteger('accounting_bill_id')->nullable();
                $table->index('accounting_bill_id');
            }
        });

        Schema::connection($conn)->table($p . 'shipments', function (Blueprint $table) use ($p): void {
            if (!Schema::hasColumn($p . 'shipments', 'supplier_id')) {
                $table->foreignId('supplier_id')->nullable()->after('to_warehouse_id')->constrained($p . 'suppliers')->nullOnDelete();
            }

            if (!Schema::hasColumn($p . 'shipments', 'accounting_bill_id')) {
                $table->unsignedBigInteger('accounting_bill_id')->nullable();
                $table->index('accounting_bill_id');
            }
        });
    }

    public function down(): void
    {
        $p = config('inventory.table_prefix', 'inv_');
        $conn = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($conn)->table($p . 'transfers', function (Blueprint $table) use ($p): void {
            $table->dropForeign([$p . 'transfers_supplier_id_foreign']);
            $table->dropColumn(['supplier_id', 'accounting_bill_id']);
        });

        Schema::connection($conn)->table($p . 'shipments', function (Blueprint $table) use ($p): void {
            $table->dropForeign([$p . 'shipments_supplier_id_foreign']);
            $table->dropColumn(['supplier_id', 'accounting_bill_id']);
        });
    }
};

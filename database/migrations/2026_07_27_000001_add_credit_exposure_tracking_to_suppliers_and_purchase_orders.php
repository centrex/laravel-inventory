<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class() extends Migration
{
    public function up(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $connection = config('inventory.drivers.database.connection', config('database.default'));
        $schema = Schema::connection($connection);

        $suppliersTable = $prefix . 'suppliers';
        $purchaseOrdersTable = $prefix . 'purchase_orders';

        if (!$schema->hasColumn($suppliersTable, 'credit_limit_amount')) {
            $schema->table($suppliersTable, function (Blueprint $table): void {
                $table->decimal('credit_limit_amount', 18, 4)->default(0)->after('currency');
            });
        }

        if (!$schema->hasColumn($purchaseOrdersTable, 'paid_amount')) {
            $schema->table($purchaseOrdersTable, function (Blueprint $table): void {
                $table->decimal('paid_amount', 18, 4)->default(0)->after('total_amount');
            });
        }

        if (!$schema->hasColumn($purchaseOrdersTable, 'due_amount')) {
            $schema->table($purchaseOrdersTable, function (Blueprint $table): void {
                $table->decimal('due_amount', 18, 4)->default(0)->after('paid_amount');
            });
        }

        // Initialise existing rows: due = total (no payment info yet)
        DB::connection($connection)->statement("UPDATE `{$purchaseOrdersTable}` SET due_amount = total_amount WHERE due_amount = 0");
    }

    public function down(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $connection = config('inventory.drivers.database.connection', config('database.default'));
        $schema = Schema::connection($connection);

        $schema->table($prefix . 'suppliers', function (Blueprint $table): void {
            $table->dropColumn('credit_limit_amount');
        });

        $schema->table($prefix . 'purchase_orders', function (Blueprint $table): void {
            $table->dropColumn(['paid_amount', 'due_amount']);
        });
    }
};

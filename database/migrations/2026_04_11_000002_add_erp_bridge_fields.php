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
        $connection = config('inventory.drivers.database.connection', config('database.default'));

        if (!Schema::connection($connection)->hasColumn($prefix . 'suppliers', 'accounting_vendor_id')) {
            Schema::connection($connection)->table($prefix . 'suppliers', function (Blueprint $table): void {
                $table->string('modelable_type')->nullable()->after('is_active');
                $table->unsignedBigInteger('modelable_id')->nullable()->after('modelable_type');
                $table->unsignedBigInteger('accounting_vendor_id')->nullable()->after('modelable_id');

                $table->index(['modelable_type', 'modelable_id']);
                $table->index('accounting_vendor_id');
            });
        }

        if (!Schema::connection($connection)->hasColumn($prefix . 'customers', 'accounting_customer_id')) {
            Schema::connection($connection)->table($prefix . 'customers', function (Blueprint $table): void {
                $table->unsignedBigInteger('accounting_customer_id')->nullable()->after('modelable_id');
                $table->index('accounting_customer_id');
            });
        }

        if (!Schema::connection($connection)->hasColumn($prefix . 'purchase_orders', 'accounting_bill_id')) {
            Schema::connection($connection)->table($prefix . 'purchase_orders', function (Blueprint $table): void {
                $table->unsignedBigInteger('accounting_bill_id')->nullable()->after('created_by');
                $table->index('accounting_bill_id');
            });
        }

        if (!Schema::connection($connection)->hasColumn($prefix . 'stock_receipts', 'accounting_journal_entry_id')) {
            Schema::connection($connection)->table($prefix . 'stock_receipts', function (Blueprint $table): void {
                $table->unsignedBigInteger('accounting_journal_entry_id')->nullable()->after('created_by');
                $table->index('accounting_journal_entry_id');
            });
        }

        if (!Schema::connection($connection)->hasColumn($prefix . 'sale_orders', 'accounting_invoice_id')) {
            Schema::connection($connection)->table($prefix . 'sale_orders', function (Blueprint $table): void {
                $table->unsignedBigInteger('accounting_invoice_id')->nullable()->after('created_by');
                $table->index('accounting_invoice_id');
            });
        }

        if (!Schema::connection($connection)->hasColumn($prefix . 'adjustments', 'accounting_journal_entry_id')) {
            Schema::connection($connection)->table($prefix . 'adjustments', function (Blueprint $table): void {
                $table->unsignedBigInteger('accounting_journal_entry_id')->nullable()->after('created_by');
                $table->index('accounting_journal_entry_id');
            });
        }
    }

    public function down(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $connection = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->table($prefix . 'adjustments', function (Blueprint $table): void {
            $table->dropIndex($prefix . 'adjustments_accounting_journal_entry_id_index');
            $table->dropColumn('accounting_journal_entry_id');
        });

        Schema::connection($connection)->table($prefix . 'sale_orders', function (Blueprint $table): void {
            $table->dropIndex($prefix . 'sale_orders_accounting_invoice_id_index');
            $table->dropColumn('accounting_invoice_id');
        });

        Schema::connection($connection)->table($prefix . 'stock_receipts', function (Blueprint $table): void {
            $table->dropIndex($prefix . 'stock_receipts_accounting_journal_entry_id_index');
            $table->dropColumn('accounting_journal_entry_id');
        });

        Schema::connection($connection)->table($prefix . 'purchase_orders', function (Blueprint $table): void {
            $table->dropIndex($prefix . 'purchase_orders_accounting_bill_id_index');
            $table->dropColumn('accounting_bill_id');
        });

        Schema::connection($connection)->table($prefix . 'customers', function (Blueprint $table): void {
            $table->dropIndex($prefix . 'customers_accounting_customer_id_index');
            $table->dropColumn('accounting_customer_id');
        });

        Schema::connection($connection)->table($prefix . 'suppliers', function (Blueprint $table): void {
            $table->dropIndex($prefix . 'suppliers_modelable_type_modelable_id_index');
            $table->dropIndex($prefix . 'suppliers_accounting_vendor_id_index');
            $table->dropColumn(['modelable_type', 'modelable_id', 'accounting_vendor_id']);
        });
    }
};

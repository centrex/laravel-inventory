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

        // Read-model mirror of laravel-accounting's Payment (invoice + bill payments, one
        // polymorphic table there) — kept in sync by PaymentMirrorObserver. accounting_payment_id
        // is the upsert key; payable_type/payable_id are passed through as-is (accounting's own
        // FQCN + id) rather than FK'd, since accounting may live on a different DB connection.
        Schema::connection($c)->create($p . 'payments', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->unsignedBigInteger('accounting_payment_id')->unique();
            $table->string('direction', 10); // sale|purchase — derived from payable_type
            $table->string('payable_type', 200);
            $table->unsignedBigInteger('payable_id');
            $table->foreignId('sale_order_id')->nullable()->constrained($p . 'sale_orders')->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained($p . 'purchase_orders')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained($p . 'customers')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained($p . 'suppliers')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained($p . 'warehouses')->nullOnDelete();
            $table->string('payment_number', 100)->nullable();
            $table->date('payment_date')->nullable();
            $table->decimal('amount', 18, 4)->default(0);
            $table->string('currency', 3)->nullable();
            $table->string('payment_method', 50)->nullable();
            $table->string('reference', 200)->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('direction');
            $table->index('payment_date');
        });

        // Read-model mirror of laravel-accounting's Expense (invoice/bill charges & discounts,
        // and standalone expenses). chargeable_type/chargeable_id are the accounting-side
        // polymorphic parent (Invoice/Bill/null), passed through the same way as payments above.
        Schema::connection($c)->create($p . 'expenses', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->unsignedBigInteger('accounting_expense_id')->unique();
            $table->string('direction', 10); // sale|purchase|standalone — derived from chargeable_type
            $table->string('chargeable_type', 200)->nullable();
            $table->unsignedBigInteger('chargeable_id')->nullable();
            $table->foreignId('sale_order_id')->nullable()->constrained($p . 'sale_orders')->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained($p . 'purchase_orders')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained($p . 'customers')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained($p . 'suppliers')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained($p . 'warehouses')->nullOnDelete();
            $table->string('expense_number', 100)->nullable();
            // Passed through, not FK'd — accounting's chart of accounts lives in its own
            // connection/table; account_code/account_name are denormalized for display.
            $table->unsignedBigInteger('accounting_account_id')->nullable();
            $table->string('account_code', 20)->nullable();
            $table->string('account_name', 200)->nullable();
            $table->date('expense_date')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->decimal('paid_amount', 18, 4)->default(0);
            $table->string('currency', 3)->nullable();
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->string('status', 30)->nullable();
            $table->string('payment_method', 50)->nullable();
            $table->string('reference', 200)->nullable();
            $table->string('vendor_name', 200)->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('direction');
            $table->index('expense_date');
        });
    }

    public function down(): void
    {
        $p = config('inventory.table_prefix', 'inv_');
        $c = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($c)->dropIfExists($p . 'expenses');
        Schema::connection($c)->dropIfExists($p . 'payments');
    }
};

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

        // Employees
        Schema::connection($connection)->create($prefix . 'employees', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('currency', 3)->default('BDT');
            $table->decimal('credit_limit', 18, 2)->default(0);
            $table->integer('payment_terms')->default(30);
            $table->boolean('is_active')->default(true);
            $table->nullableMorphs('modelable');
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });

        // Payroll Accounts (salary heads / pay codes)
        Schema::connection($connection)->create($prefix . 'payroll_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('currency', 3)->default('BDT');
            $table->boolean('is_active')->default(true);
            $table->text('particulars')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['code', 'is_active']);
        });

        // Payroll Entries (payroll runs)
        Schema::connection($connection)->create($prefix . 'payroll_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('entry_number')->unique();
            $table->date('date');
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->string('currency', 3)->default('BDT');
            $table->string('type'); // salary, bonus, deduction, tax
            $table->decimal('exchange_rate', 10, 6)->default(1.000000);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['date', 'status']);
            $table->index('entry_number');
        });

        // Payroll Entry Lines
        Schema::connection($connection)->create($prefix . 'payroll_entry_lines', function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('payroll_entry_id')->constrained($prefix . 'payroll_entries')->onDelete('cascade');
            $table->foreignId('payroll_account_id')->constrained($prefix . 'payroll_accounts')->onDelete('restrict');
            $table->decimal('amount', 18, 2);
            $table->text('description')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();

            $table->index('payroll_entry_id');
            $table->index('payroll_account_id');
        });

        // Expenses (General Expenses)
        Schema::connection($connection)->create($prefix . 'expenses', function (Blueprint $table): void {
            $table->id();
            $table->string('expense_number')->unique();
            // account_id references accounting's accounts table — stored without FK to allow different connections
            $table->unsignedBigInteger('account_id')->nullable();
            $table->date('expense_date');
            $table->date('due_date')->nullable();
            $table->decimal('subtotal', 18, 2);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->string('currency', 3)->default('BDT');
            $table->decimal('exchange_rate', 10, 6)->default(1.000000);
            $table->string('status')->default('draft');
            $table->string('payment_method')->nullable();
            $table->string('reference')->nullable();
            $table->string('vendor_name')->nullable();
            $table->text('notes')->nullable();
            // journal_entry_id references accounting's journal_entries — stored without FK
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'expense_date']);
            $table->index('account_id');
            $table->index('expense_date');
        });

        // Expense Items
        Schema::connection($connection)->create($prefix . 'expense_items', function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('expense_id')->constrained($prefix . 'expenses')->onDelete('cascade');
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('amount', 18, 2);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->string('reference')->nullable();
            $table->timestamps();

            $table->index('expense_id');
        });
    }

    public function down(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $connection = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->dropIfExists($prefix . 'expense_items');
        Schema::connection($connection)->dropIfExists($prefix . 'expenses');
        Schema::connection($connection)->dropIfExists($prefix . 'payroll_entry_lines');
        Schema::connection($connection)->dropIfExists($prefix . 'payroll_entries');
        Schema::connection($connection)->dropIfExists($prefix . 'payroll_accounts');
        Schema::connection($connection)->dropIfExists($prefix . 'employees');
    }
};

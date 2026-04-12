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

        Schema::connection($connection)->table($prefix . 'employees', function (Blueprint $table): void {
            $table->string('department')->nullable()->after('country');
            $table->string('designation')->nullable()->after('department');
            $table->string('employment_type')->default('full_time')->after('designation');
            $table->date('joining_date')->nullable()->after('employment_type');
            $table->decimal('monthly_salary', 18, 2)->default(0)->after('joining_date');
            $table->string('bank_account_name')->nullable()->after('monthly_salary');
            $table->string('bank_account_number')->nullable()->after('bank_account_name');
            $table->string('emergency_contact_name')->nullable()->after('bank_account_number');
            $table->string('emergency_contact_phone')->nullable()->after('emergency_contact_name');
            $table->index(['department', 'is_active'], 'employees_department_active_idx');
        });

        Schema::connection($connection)->table($prefix . 'payroll_entry_lines', function (Blueprint $table) use ($prefix): void {
            $table->foreignId('employee_id')
                ->nullable()
                ->after('payroll_entry_id')
                ->constrained($prefix . 'employees')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $connection = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($connection)->table($prefix . 'payroll_entry_lines', function (Blueprint $table): void {
            $table->dropForeign(['employee_id']);
            $table->dropColumn('employee_id');
        });

        Schema::connection($connection)->table($prefix . 'employees', function (Blueprint $table): void {
            $table->dropIndex('employees_department_active_idx');
            $table->dropColumn([
                'department',
                'designation',
                'employment_type',
                'joining_date',
                'monthly_salary',
                'bank_account_name',
                'bank_account_number',
                'emergency_contact_name',
                'emergency_contact_phone',
            ]);
        });
    }
};

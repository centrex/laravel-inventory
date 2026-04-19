<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        $p = config('inventory.table_prefix') ?: 'inv_';
        $c = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($c)->table($p . 'customers', function (Blueprint $table): void {
            $table->decimal('credit_limit_amount', 18, 4)->default(0)->after('currency');
        });

        Schema::connection($c)->table($p . 'sale_orders', function (Blueprint $table): void {
            $table->decimal('credit_limit_amount', 18, 4)->default(0)->after('total_amount');
            $table->decimal('credit_exposure_before_amount', 18, 4)->default(0)->after('credit_limit_amount');
            $table->decimal('credit_exposure_after_amount', 18, 4)->default(0)->after('credit_exposure_before_amount');
            $table->boolean('credit_override_required')->default(false)->after('credit_exposure_after_amount');
            $table->unsignedBigInteger('credit_override_approved_by')->nullable()->after('credit_override_required');
            $table->timestamp('credit_override_approved_at')->nullable()->after('credit_override_approved_by');
            $table->text('credit_override_notes')->nullable()->after('credit_override_approved_at');

            $table->index('credit_override_required');
            $table->index('credit_override_approved_by');
        });

        Schema::connection($c)->create($p . 'transfer_boxes', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->foreignId('transfer_id')->constrained($p . 'transfers')->onDelete('cascade');
            $table->string('box_code', 50)->nullable();
            $table->decimal('measured_weight_kg', 18, 4);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index('transfer_id');
        });

        Schema::connection($c)->create($p . 'transfer_box_items', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->foreignId('transfer_box_id')->constrained($p . 'transfer_boxes')->onDelete('cascade');
            $table->foreignId('product_id')->constrained($p . 'products')->onDelete('restrict');
            $table->decimal('qty_sent', 18, 4);
            $table->decimal('theoretical_weight_kg', 18, 4)->default(0);
            $table->decimal('allocated_weight_kg', 18, 4)->default(0);
            $table->decimal('weight_ratio', 18, 8)->default(0);
            $table->decimal('source_unit_cost_amount', 18, 4)->default(0);
            $table->decimal('shipping_allocated_amount', 18, 4)->default(0);
            $table->decimal('unit_landed_cost_amount', 18, 4)->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index('transfer_box_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        $p = config('inventory.table_prefix') ?: 'inv_';
        $c = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($c)->dropIfExists($p . 'transfer_box_items');
        Schema::connection($c)->dropIfExists($p . 'transfer_boxes');

        Schema::connection($c)->table($p . 'sale_orders', function (Blueprint $table): void {
            $table->dropIndex($table->getTable() . '_credit_override_required_index');
            $table->dropIndex($table->getTable() . '_credit_override_approved_by_index');
            $table->dropColumn([
                'credit_limit_amount',
                'credit_exposure_before_amount',
                'credit_exposure_after_amount',
                'credit_override_required',
                'credit_override_approved_by',
                'credit_override_approved_at',
                'credit_override_notes',
            ]);
        });

        Schema::connection($c)->table($p . 'customers', function (Blueprint $table): void {
            $table->dropColumn('credit_limit_amount');
        });
    }
};

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
        $withUserForeignKeys = (bool) config('inventory.user_foreign_keys', false);

        Schema::connection($c)->table($p . 'sale_orders', function (Blueprint $table): void {
            $table->string('document_type', 30)->default('order')->after('so_number')->index();
        });

        Schema::connection($c)->table($p . 'purchase_orders', function (Blueprint $table): void {
            $table->string('document_type', 30)->default('order')->after('po_number')->index();
        });

        Schema::connection($c)->create($p . 'sale_returns', function (Blueprint $table) use ($p, $withUserForeignKeys): void {
            $table->id();
            $table->string('return_number', 50)->unique();
            $table->foreignId('sale_order_id')->nullable()->constrained($p . 'sale_orders')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained($p . 'warehouses')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained($p . 'customers')->nullOnDelete();
            $table->string('status', 30)->default('draft');
            $table->timestamp('returned_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['warehouse_id', 'status']);
            $table->index('customer_id');
            $table->index('sale_order_id');

            if ($withUserForeignKeys) {
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            }
        });

        Schema::connection($c)->create($p . 'sale_return_items', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->foreignId('sale_return_id')->constrained($p . 'sale_returns')->cascadeOnDelete();
            $table->foreignId('sale_order_item_id')->nullable()->constrained($p . 'sale_order_items')->nullOnDelete();
            $table->foreignId('product_id')->constrained($p . 'products')->restrictOnDelete();
            $table->decimal('qty_returned', 18, 4);
            $table->decimal('unit_price_amount', 18, 4)->default(0);
            $table->decimal('unit_cost_amount', 18, 4)->default(0);
            $table->decimal('line_total_amount', 18, 4)->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index('product_id');
        });

        Schema::connection($c)->create($p . 'purchase_returns', function (Blueprint $table) use ($p, $withUserForeignKeys): void {
            $table->id();
            $table->string('return_number', 50)->unique();
            $table->foreignId('purchase_order_id')->nullable()->constrained($p . 'purchase_orders')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained($p . 'warehouses')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained($p . 'suppliers')->restrictOnDelete();
            $table->string('status', 30)->default('draft');
            $table->timestamp('returned_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['warehouse_id', 'status']);
            $table->index('supplier_id');
            $table->index('purchase_order_id');

            if ($withUserForeignKeys) {
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            }
        });

        Schema::connection($c)->create($p . 'purchase_return_items', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained($p . 'purchase_returns')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained($p . 'purchase_order_items')->nullOnDelete();
            $table->foreignId('product_id')->constrained($p . 'products')->restrictOnDelete();
            $table->decimal('qty_returned', 18, 4);
            $table->decimal('unit_cost_amount', 18, 4)->default(0);
            $table->decimal('line_total_amount', 18, 4)->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        $p = config('inventory.table_prefix') ?: 'inv_';
        $c = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($c)->dropIfExists($p . 'purchase_return_items');
        Schema::connection($c)->dropIfExists($p . 'purchase_returns');
        Schema::connection($c)->dropIfExists($p . 'sale_return_items');
        Schema::connection($c)->dropIfExists($p . 'sale_returns');

        Schema::connection($c)->table($p . 'purchase_orders', function (Blueprint $table): void {
            $table->dropColumn('document_type');
        });

        Schema::connection($c)->table($p . 'sale_orders', function (Blueprint $table): void {
            $table->dropColumn('document_type');
        });
    }
};

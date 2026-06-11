<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        $c = config('inventory.drivers.database.connection', config('database.default'));
        $p = config('inventory.table_prefix', 'inv_');

        Schema::connection($c)->create($p . 'transfer_boxes', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->foreignId('transfer_id')->constrained($p . 'transfers')->cascadeOnDelete();
            $table->string('box_code', 50)->nullable();
            $table->decimal('measured_weight_kg', 18, 4);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index('transfer_id');
        });

        Schema::connection($c)->create($p . 'transfer_box_items', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->foreignId('transfer_box_id')->constrained($p . 'transfer_boxes')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained($p . 'products')->restrictOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable()->index();
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
            $table->index(['product_id', 'variant_id'], $p . 'transfer_box_items_product_variant_idx');
        });
    }

    public function down(): void
    {
        $c = config('inventory.drivers.database.connection', config('database.default'));
        $p = config('inventory.table_prefix', 'inv_');

        Schema::connection($c)->dropIfExists($p . 'transfer_box_items');
        Schema::connection($c)->dropIfExists($p . 'transfer_boxes');
    }
};

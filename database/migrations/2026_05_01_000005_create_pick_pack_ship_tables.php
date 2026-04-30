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
        $c = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($c)->create($p . 'pick_lists', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->string('pick_number', 40)->unique();
            $table->foreignId('sale_order_id')->constrained($p . 'sale_orders')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained($p . 'warehouses');
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->string('status', 20)->default('draft');  // draft|picking|picked|cancelled
            $table->text('notes')->nullable();
            $table->timestamp('picked_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::connection($c)->create($p . 'pick_list_items', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->foreignId('pick_list_id')->constrained($p . 'pick_lists')->cascadeOnDelete();
            $table->foreignId('sale_order_item_id')->constrained($p . 'sale_order_items');
            $table->foreignId('product_id')->constrained($p . 'products');
            $table->unsignedBigInteger('variant_id')->nullable()->index();
            $table->unsignedBigInteger('lot_id')->nullable()->index();
            $table->string('bin_location', 50)->nullable();
            $table->decimal('qty_to_pick', 18, 4)->default(0);
            $table->decimal('qty_picked', 18, 4)->default(0);
            $table->json('serial_numbers')->nullable();
            $table->timestamps();
        });

        Schema::connection($c)->create($p . 'shipments', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->string('shipment_number', 40)->unique();
            $table->foreignId('sale_order_id')->constrained($p . 'sale_orders');
            $table->foreignId('warehouse_id')->constrained($p . 'warehouses');
            $table->string('carrier', 80)->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->string('status', 20)->default('pending');  // pending|dispatched|delivered|returned
            $table->text('notes')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('estimated_delivery_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::connection($c)->create($p . 'shipment_items', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained($p . 'shipments')->cascadeOnDelete();
            $table->foreignId('sale_order_item_id')->constrained($p . 'sale_order_items');
            $table->foreignId('product_id')->constrained($p . 'products');
            $table->unsignedBigInteger('variant_id')->nullable()->index();
            $table->unsignedBigInteger('lot_id')->nullable()->index();
            $table->decimal('qty_shipped', 18, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $p = config('inventory.table_prefix', 'inv_');
        $c = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($c)->dropIfExists($p . 'shipment_items');
        Schema::connection($c)->dropIfExists($p . 'shipments');
        Schema::connection($c)->dropIfExists($p . 'pick_list_items');
        Schema::connection($c)->dropIfExists($p . 'pick_lists');
    }
};

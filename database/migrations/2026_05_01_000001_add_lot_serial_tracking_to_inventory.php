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

        // ── Lots (batch / batch tracking) ─────────────────────────────────────
        Schema::connection($c)->create($p . 'lots', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->string('lot_number', 100);
            $table->foreignId('product_id')->constrained($p . 'products')->restrictOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable()->index();
            $table->foreignId('warehouse_id')->constrained($p . 'warehouses')->restrictOnDelete();
            $table->unsignedBigInteger('purchase_order_item_id')->nullable()->index();
            $table->date('manufactured_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->decimal('qty_initial', 18, 4)->default(0);
            $table->decimal('qty_on_hand', 18, 4)->default(0);
            $table->decimal('unit_cost_amount', 18, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['product_id', 'variant_id', 'warehouse_id', 'lot_number'],
                $p . 'lots_product_warehouse_lot_unique',
            );
            $table->index(['product_id', 'variant_id', 'warehouse_id'], $p . 'lots_product_variant_warehouse_idx');
            $table->index('expires_at');
        });

        // ── Serial Numbers (unit-level tracking) ──────────────────────────────
        Schema::connection($c)->create($p . 'serial_numbers', function (Blueprint $table) use ($p): void {
            $table->id();
            $table->string('serial_number', 150)->unique();
            $table->foreignId('product_id')->constrained($p . 'products')->restrictOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable()->index();
            $table->unsignedBigInteger('lot_id')->nullable()->index();
            $table->foreignId('warehouse_id')->constrained($p . 'warehouses')->restrictOnDelete();
            $table->unsignedBigInteger('purchase_order_item_id')->nullable()->index();
            $table->unsignedBigInteger('sale_order_item_id')->nullable()->index();
            // available | reserved | sold | returned | damaged | lost
            $table->string('status', 30)->default('available')->index();
            $table->timestamps();

            $table->index(
                ['product_id', 'warehouse_id', 'status'],
                $p . 'serial_numbers_product_warehouse_status_idx',
            );
        });

        // ── lot_id on stock_receipt_items ─────────────────────────────────────
        Schema::connection($c)->table($p . 'stock_receipt_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('lot_id')->nullable()->after('variant_id')->index();
            // JSON array of serial numbers assigned at GRN time, e.g. ["SN001","SN002"]
            $table->json('serial_numbers')->nullable()->after('lot_id');
        });

        // ── lot_id on sale_order_items ────────────────────────────────────────
        Schema::connection($c)->table($p . 'sale_order_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('lot_id')->nullable()->after('variant_id')->index();
        });

        // ── lot_id on stock_movements ─────────────────────────────────────────
        Schema::connection($c)->table($p . 'stock_movements', function (Blueprint $table): void {
            $table->unsignedBigInteger('lot_id')->nullable()->after('variant_id')->index();
        });
    }

    public function down(): void
    {
        $p = config('inventory.table_prefix', 'inv_');
        $c = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($c)->table($p . 'stock_movements', function (Blueprint $table): void {
            $table->dropColumn('lot_id');
        });

        Schema::connection($c)->table($p . 'sale_order_items', function (Blueprint $table): void {
            $table->dropColumn('lot_id');
        });

        Schema::connection($c)->table($p . 'stock_receipt_items', function (Blueprint $table): void {
            $table->dropColumn(['lot_id', 'serial_numbers']);
        });

        Schema::connection($c)->dropIfExists($p . 'serial_numbers');
        Schema::connection($c)->dropIfExists($p . 'lots');
    }
};

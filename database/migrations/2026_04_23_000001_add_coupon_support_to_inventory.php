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

        Schema::connection($c)->create($p . 'coupons', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150)->nullable();
            $table->text('description')->nullable();
            $table->string('discount_type', 20);
            $table->decimal('discount_value', 18, 4);
            $table->decimal('minimum_subtotal_amount', 18, 4)->nullable();
            $table->decimal('maximum_discount_amount', 18, 4)->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::connection($c)->table($p . 'sale_orders', function (Blueprint $table) use ($p): void {
            $table->foreignId('coupon_id')->nullable()->after('customer_id')->constrained($p . 'coupons')->nullOnDelete();
            $table->string('coupon_code', 50)->nullable()->after('price_tier_code');
            $table->string('coupon_name', 150)->nullable()->after('coupon_code');
            $table->string('coupon_discount_type', 20)->nullable()->after('coupon_name');
            $table->decimal('coupon_discount_value', 18, 4)->default(0)->after('coupon_discount_type');
            $table->decimal('coupon_discount_local', 18, 4)->default(0)->after('discount_local');
            $table->decimal('coupon_discount_amount', 18, 4)->default(0)->after('discount_amount');

            $table->index('coupon_code', $p . 'sale_orders_coupon_code_idx');
            $table->index('coupon_id', $p . 'sale_orders_coupon_id_idx');
        });
    }

    public function down(): void
    {
        $p = config('inventory.table_prefix') ?: 'inv_';
        $c = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($c)->table($p . 'sale_orders', function (Blueprint $table): void {
            $table->dropIndex($p . 'sale_orders_coupon_code_idx');
            $table->dropIndex($p . 'sale_orders_coupon_id_idx');
            $table->dropForeign(['coupon_id']);
            $table->dropColumn([
                'coupon_id',
                'coupon_code',
                'coupon_name',
                'coupon_discount_type',
                'coupon_discount_value',
                'coupon_discount_local',
                'coupon_discount_amount',
            ]);
        });

        Schema::connection($c)->dropIfExists($p . 'coupons');
    }
};

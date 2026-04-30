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

        Schema::connection($c)->create($p . 'partners', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('type', 30)->default('dropshipper');  // dropshipper|ecom|b2b|marketplace
            $table->string('api_key', 64)->unique();
            $table->unsignedBigInteger('customer_id')->nullable()->index(); // link to inv_customers
            $table->unsignedBigInteger('default_warehouse_id')->nullable();
            $table->string('default_price_tier', 30)->default('B2B_WHOLESALE');
            $table->boolean('can_view_stock')->default(true);
            $table->boolean('can_view_prices')->default(true);
            $table->boolean('can_create_orders')->default(true);
            $table->boolean('is_active')->default(true);
            $table->json('allowed_warehouse_ids')->nullable();  // null = all warehouses
            $table->json('allowed_product_ids')->nullable();    // null = all products
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        $p = config('inventory.table_prefix', 'inv_');
        $c = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($c)->dropIfExists($p . 'partners');
    }
};

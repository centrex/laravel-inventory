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

        Schema::connection($c)->create($p . 'product_brands', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 200);
            $table->string('slug', 200)->unique();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection($c)->table($p . 'products', function (Blueprint $table) use ($p): void {
            $table->foreignId('brand_id')
                ->nullable()
                ->after('category_id')
                ->constrained($p . 'product_brands')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $p = config('inventory.table_prefix') ?: 'inv_';
        $c = config('inventory.drivers.database.connection', config('database.default'));

        Schema::connection($c)->table($p . 'products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('brand_id');
        });

        Schema::connection($c)->dropIfExists($p . 'product_brands');
    }
};

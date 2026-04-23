<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class() extends Migration
{
    public function up(): void
    {
        $p = config('inventory.table_prefix') ?: 'inv_';
        $c = config('inventory.drivers.database.connection', config('database.default'));

        if (!Schema::connection($c)->hasTable($p . 'product_variants')) {
            Schema::connection($c)->create($p . 'product_variants', function (Blueprint $table) use ($p): void {
                $table->id();
                $table->foreignId('product_id')->constrained($p . 'products')->cascadeOnDelete();
                $table->string('sku', 100)->unique();
                $table->string('name', 200);
                $table->string('barcode', 100)->nullable()->unique();
                $table->decimal('weight_kg', 10, 4)->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->json('attributes')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['product_id', 'is_active']);
            });
        }

        if (!Schema::connection($c)->hasColumn($p . 'product_prices', 'variant_id')) {
            Schema::connection($c)->table($p . 'product_prices', function (Blueprint $table) use ($p): void {
                $table->foreignId('variant_id')->nullable()->after('product_id')->constrained($p . 'product_variants')->nullOnDelete();
            });
        }

        if (!$this->indexExists($c, $p . 'product_prices', $p . 'product_prices_variant_lookup_idx')) {
            Schema::connection($c)->table($p . 'product_prices', function (Blueprint $table) use ($p): void {
                $table->index(['product_id', 'variant_id', 'price_tier_code'], $p . 'product_prices_variant_lookup_idx');
            });
        }

        if (!Schema::connection($c)->hasColumn($p . 'warehouse_products', 'variant_id')) {
            Schema::connection($c)->table($p . 'warehouse_products', function (Blueprint $table) use ($p): void {
                $table->foreignId('variant_id')->nullable()->after('product_id')->constrained($p . 'product_variants')->nullOnDelete();
            });
        }

        Schema::connection($c)->table($p . 'warehouse_products', function (Blueprint $table) use ($p, $c): void {
            if (!$this->indexExists($c, $p . 'warehouse_products', $p . 'warehouse_products_warehouse_fk_idx')) {
                $table->index('warehouse_id', $p . 'warehouse_products_warehouse_fk_idx');
            }

            if (!$this->indexExists($c, $p . 'warehouse_products', $p . 'warehouse_products_product_fk_idx')) {
                $table->index('product_id', $p . 'warehouse_products_product_fk_idx');
            }
        });

        if ($this->indexExists($c, $p . 'warehouse_products', $p . 'warehouse_products_warehouse_id_product_id_unique')) {
            Schema::connection($c)->table($p . 'warehouse_products', function (Blueprint $table): void {
                $table->dropUnique(['warehouse_id', 'product_id']);
            });
        }

        if (!$this->indexExists($c, $p . 'warehouse_products', $p . 'warehouse_products_variant_lookup_idx')) {
            Schema::connection($c)->table($p . 'warehouse_products', function (Blueprint $table) use ($p): void {
                $table->index(['warehouse_id', 'product_id', 'variant_id'], $p . 'warehouse_products_variant_lookup_idx');
            });
        }

        foreach ([
            'purchase_order_items',
            'stock_receipt_items',
            'sale_order_items',
            'sale_return_items',
            'purchase_return_items',
            'transfer_items',
            'transfer_box_items',
            'stock_movements',
            'adjustment_items',
        ] as $tableSuffix) {
            if (!Schema::connection($c)->hasColumn($p . $tableSuffix, 'variant_id')) {
                Schema::connection($c)->table($p . $tableSuffix, function (Blueprint $table) use ($p): void {
                    $table->foreignId('variant_id')->nullable()->after('product_id')->constrained($p . 'product_variants')->nullOnDelete();
                });
            }

            $indexName = $p . $tableSuffix . '_product_variant_idx';

            if (!$this->indexExists($c, $p . $tableSuffix, $indexName)) {
                Schema::connection($c)->table($p . $tableSuffix, function (Blueprint $table) use ($indexName): void {
                    $table->index(['product_id', 'variant_id'], $indexName);
                });
            }
        }
    }

    public function down(): void
    {
        $p = config('inventory.table_prefix') ?: 'inv_';
        $c = config('inventory.drivers.database.connection', config('database.default'));

        foreach ([
            'purchase_order_items',
            'stock_receipt_items',
            'sale_order_items',
            'sale_return_items',
            'purchase_return_items',
            'transfer_items',
            'transfer_box_items',
            'stock_movements',
            'adjustment_items',
        ] as $tableSuffix) {
            Schema::connection($c)->table($p . $tableSuffix, function (Blueprint $table): void {
                $table->dropIndex($table->getTable() . '_product_variant_idx');
                $table->dropForeign(['variant_id']);
                $table->dropColumn('variant_id');
            });
        }

        Schema::connection($c)->table($p . 'warehouse_products', function (Blueprint $table) use ($p): void {
            $table->dropIndex($p . 'warehouse_products_variant_lookup_idx');
            $table->dropForeign(['variant_id']);
            $table->dropColumn('variant_id');
            $table->unique(['warehouse_id', 'product_id']);
            $table->dropIndex($p . 'warehouse_products_warehouse_fk_idx');
            $table->dropIndex($p . 'warehouse_products_product_fk_idx');
        });

        Schema::connection($c)->table($p . 'product_prices', function (Blueprint $table) use ($p): void {
            $table->dropIndex($p . 'product_prices_variant_lookup_idx');
            $table->dropForeign(['variant_id']);
            $table->dropColumn('variant_id');
        });

        Schema::connection($c)->dropIfExists($p . 'product_variants');
    }

    private function indexExists(?string $connection, string $table, string $index): bool
    {
        $driver = DB::connection($connection)->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::connection($connection)->select("PRAGMA index_list('{$table}')");

            return collect($indexes)->contains(fn (object $row): bool => ($row->name ?? null) === $index);
        }

        return DB::connection($connection)
            ->table('information_schema.statistics')
            ->where('table_schema', DB::connection($connection)->getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};

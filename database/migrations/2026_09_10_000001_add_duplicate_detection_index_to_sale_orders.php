<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DuplicateSaleOrderDetector::find() runs synchronously on every checkout (SaleController@store)
 * filtering sale_orders by customer_id/warehouse_id/document_type/created_by/status/created_at,
 * ordered by id desc limit 5 — but no index covered that combination, and created_at had no
 * index at all. For the normal (non-duplicate) case the WHERE matches zero rows, so a backwards
 * scan on id to satisfy "ORDER BY id DESC LIMIT 5" has nothing to bound it by created_at and
 * walks the entire table before concluding there's no match — cost grows with total order
 * history rather than with actual duplicate submissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $conn = config('inventory.drivers.database.connection', config('database.default'));
        $schema = Schema::connection($conn);

        $saleOrders = $prefix . 'sale_orders';

        $this->addIndexIfMissing($schema, $saleOrders, $prefix . 'sale_orders_dup_detect_idx', function (Blueprint $table) use ($prefix): void {
            $table->index(['customer_id', 'warehouse_id', 'document_type', 'created_by', 'created_at'], $prefix . 'sale_orders_dup_detect_idx');
        });
    }

    public function down(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $conn = config('inventory.drivers.database.connection', config('database.default'));
        $schema = Schema::connection($conn);

        $saleOrders = $prefix . 'sale_orders';

        $this->dropIndexIfExists($schema, $saleOrders, $prefix . 'sale_orders_dup_detect_idx');
    }

    private function addIndexIfMissing(Illuminate\Database\Schema\Builder $schema, string $table, string $indexName, Closure $callback): void
    {
        if ($this->hasIndex($schema, $table, $indexName)) {
            return;
        }

        $schema->table($table, $callback);
    }

    private function dropIndexIfExists(Illuminate\Database\Schema\Builder $schema, string $table, string $indexName): void
    {
        if (!$this->hasIndex($schema, $table, $indexName)) {
            return;
        }

        $schema->table($table, function (Blueprint $blueprint) use ($indexName): void {
            $blueprint->dropIndex($indexName);
        });
    }

    /**
     * Portable-enough existence check — Schema has no first-class "has index" helper prior to
     * the getIndexes() addition, and this migration needs to be safe to re-run.
     */
    private function hasIndex(Illuminate\Database\Schema\Builder $schema, string $table, string $indexName): bool
    {
        return collect($schema->getIndexes($table))
            ->contains(fn (array $index): bool => $index['name'] === $indexName);
    }
};

<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The index added by 2026_09_10_000001 orders its columns
 * (customer_id, warehouse_id, document_type, created_by, created_at). That works for every
 * DuplicateSaleOrderDetector::find() caller that passes createdBy (SaleController,
 * SaleOrderFormPage, InventoryWorkflowController, RetailStorefront) — but
 * PartnerApiController::createOrder() never has a createdBy to pass (partner auth is an API
 * key, not a Laravel user; see its own comment), so that call always skips the created_by
 * filter. Since created_by sits in the middle of the index, skipping it breaks the
 * leftmost-prefix rule: MySQL can only use the index through document_type, then falls back
 * to scanning every row that partner's linked customer has ever ordered in that warehouse —
 * still unbounded by total order history for a busy partner, same failure mode
 * 2026_09_10_000001 fixed for the other callers.
 *
 * Moving created_at (always present) ahead of created_by (only sometimes present) fixes this
 * for every caller at once: the index stays usable through the created_at range regardless of
 * whether created_by is filtered, and — since InnoDB secondary indexes support index
 * condition pushdown — created_by (when present) is still checked against the index itself
 * rather than requiring a row lookup.
 */
return new class() extends Migration
{
    public function up(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $conn = config('inventory.drivers.database.connection', config('database.default'));
        $schema = Schema::connection($conn);

        $saleOrders = $prefix . 'sale_orders';
        $indexName = $prefix . 'sale_orders_dup_detect_idx';

        if ($this->hasIndex($schema, $saleOrders, $indexName)) {
            $schema->table($saleOrders, function (Blueprint $table) use ($indexName): void {
                $table->dropIndex($indexName);
            });
        }

        $schema->table($saleOrders, function (Blueprint $table) use ($indexName): void {
            $table->index(['customer_id', 'warehouse_id', 'document_type', 'created_at', 'created_by'], $indexName);
        });
    }

    public function down(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $conn = config('inventory.drivers.database.connection', config('database.default'));
        $schema = Schema::connection($conn);

        $saleOrders = $prefix . 'sale_orders';
        $indexName = $prefix . 'sale_orders_dup_detect_idx';

        if ($this->hasIndex($schema, $saleOrders, $indexName)) {
            $schema->table($saleOrders, function (Blueprint $table) use ($indexName): void {
                $table->dropIndex($indexName);
            });
        }

        $schema->table($saleOrders, function (Blueprint $table) use ($indexName): void {
            $table->index(['customer_id', 'warehouse_id', 'document_type', 'created_by', 'created_at'], $indexName);
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

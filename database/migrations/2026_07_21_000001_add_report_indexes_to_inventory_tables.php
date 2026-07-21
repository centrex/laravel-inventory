<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report pages (Sales, Purchase, Aging) filter sale_orders/purchase_orders by ordered_at
 * range and, for the due-aging report, sale_orders.due_amount — none of which were indexed,
 * so every report render did a full table scan on these tables.
 */
return new class() extends Migration
{
    public function up(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $conn = config('inventory.drivers.database.connection', config('database.default'));
        $schema = Schema::connection($conn);

        $saleOrders = $prefix . 'sale_orders';
        $purchaseOrders = $prefix . 'purchase_orders';

        $this->addIndexIfMissing($schema, $saleOrders, $prefix . 'sale_orders_ordered_at_idx', function (Blueprint $table) use ($prefix): void {
            $table->index('ordered_at', $prefix . 'sale_orders_ordered_at_idx');
        });

        $this->addIndexIfMissing($schema, $saleOrders, $prefix . 'sale_orders_doctype_ordered_idx', function (Blueprint $table) use ($prefix): void {
            $table->index(['document_type', 'ordered_at'], $prefix . 'sale_orders_doctype_ordered_idx');
        });

        $this->addIndexIfMissing($schema, $saleOrders, $prefix . 'sale_orders_status_due_idx', function (Blueprint $table) use ($prefix): void {
            $table->index(['status', 'due_amount'], $prefix . 'sale_orders_status_due_idx');
        });

        $this->addIndexIfMissing($schema, $purchaseOrders, $prefix . 'purchase_orders_ordered_at_idx', function (Blueprint $table) use ($prefix): void {
            $table->index('ordered_at', $prefix . 'purchase_orders_ordered_at_idx');
        });

        $this->addIndexIfMissing($schema, $purchaseOrders, $prefix . 'purchase_orders_doctype_ordered_idx', function (Blueprint $table) use ($prefix): void {
            $table->index(['document_type', 'ordered_at'], $prefix . 'purchase_orders_doctype_ordered_idx');
        });
    }

    public function down(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $conn = config('inventory.drivers.database.connection', config('database.default'));
        $schema = Schema::connection($conn);

        $saleOrders = $prefix . 'sale_orders';
        $purchaseOrders = $prefix . 'purchase_orders';

        $this->dropIndexIfExists($schema, $saleOrders, $prefix . 'sale_orders_ordered_at_idx');
        $this->dropIndexIfExists($schema, $saleOrders, $prefix . 'sale_orders_doctype_ordered_idx');
        $this->dropIndexIfExists($schema, $saleOrders, $prefix . 'sale_orders_status_due_idx');
        $this->dropIndexIfExists($schema, $purchaseOrders, $prefix . 'purchase_orders_ordered_at_idx');
        $this->dropIndexIfExists($schema, $purchaseOrders, $prefix . 'purchase_orders_doctype_ordered_idx');
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

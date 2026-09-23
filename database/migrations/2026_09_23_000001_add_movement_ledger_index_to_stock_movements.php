<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The movement ledger is read as a time-ordered sequence per warehouse x product x variant:
 * Inventory::getMovementHistory() and the FIFO replay behind the stock aging report both
 * filter on that triple and then order by moved_at.
 *
 * The existing stock_movements_product_variant_idx covers the filter but stops short of
 * moved_at, so the ordering fell back to a filesort over every matching movement. Extending
 * the index to include moved_at lets both the range scan and the ordering come straight from
 * the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = $this->prefix();
        $schema = Schema::connection($this->connection());

        $table = $prefix . 'stock_movements';
        $index = $prefix . 'stock_movements_product_variant_moved_idx';

        if ($this->hasIndex($schema, $table, $index)) {
            return;
        }

        $schema->table($table, function (Blueprint $blueprint) use ($index): void {
            $blueprint->index(['warehouse_id', 'product_id', 'variant_id', 'moved_at'], $index);
        });
    }

    public function down(): void
    {
        $prefix = $this->prefix();
        $schema = Schema::connection($this->connection());

        $table = $prefix . 'stock_movements';
        $index = $prefix . 'stock_movements_product_variant_moved_idx';

        if (!$this->hasIndex($schema, $table, $index)) {
            return;
        }

        $schema->table($table, function (Blueprint $blueprint) use ($index): void {
            $blueprint->dropIndex($index);
        });
    }

    private function prefix(): string
    {
        $prefix = config('inventory.table_prefix');

        return is_string($prefix) && $prefix !== '' ? $prefix : 'inv_';
    }

    private function connection(): ?string
    {
        $connection = config('inventory.drivers.database.connection')
            ?? config('database.default');

        return is_string($connection) ? $connection : null;
    }

    private function hasIndex(Illuminate\Database\Schema\Builder $schema, string $table, string $indexName): bool
    {
        return collect($schema->getIndexes($table))
            ->contains(fn (array $index): bool => $index['name'] === $indexName);
    }
};

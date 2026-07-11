<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class() extends Migration
{
    public function up(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $table = $prefix . 'customers';
        $conn = config('inventory.drivers.database.connection', config('database.default'));
        $schema = Schema::connection($conn);

        $hasIsAgent = $schema->hasColumn($table, 'is_agent');
        $hasAgentId = $schema->hasColumn($table, 'agent_id');

        if (!$hasIsAgent || !$hasAgentId) {
            $schema->table($table, function (Blueprint $blueprint) use ($hasIsAgent, $hasAgentId): void {
                if (!$hasIsAgent) {
                    $blueprint->boolean('is_agent')->default(false)->after('is_active');
                }

                if (!$hasAgentId) {
                    $blueprint->unsignedBigInteger('agent_id')->nullable()->after('is_agent')->index();
                }
            });
        }

        if (!$this->hasForeignKey($conn, $table, 'agent_id')) {
            $schema->table($table, function (Blueprint $blueprint) use ($prefix): void {
                $blueprint->foreign('agent_id')->references('id')->on($prefix . 'customers')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $table = $prefix . 'customers';
        $conn = config('inventory.drivers.database.connection', config('database.default'));
        $schema = Schema::connection($conn);

        if ($this->hasForeignKey($conn, $table, 'agent_id')) {
            $schema->table($table, function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['agent_id']);
            });
        }

        $columnsToDrop = array_filter(
            ['is_agent', 'agent_id'],
            fn (string $column): bool => $schema->hasColumn($table, $column),
        );

        if ($columnsToDrop !== []) {
            $schema->table($table, function (Blueprint $blueprint) use ($columnsToDrop): void {
                $blueprint->dropColumn($columnsToDrop);
            });
        }
    }

    /**
     * Portable-enough existence check for a foreign key on $column — Laravel's
     * Schema facade has no first-class "has foreign key" helper, and this
     * migration needs to be safe to re-run against a database where an
     * earlier (since-removed) migration already created the same constraint.
     */
    private function hasForeignKey(?string $connectionName, string $table, string $column): bool
    {
        $connection = DB::connection($connectionName);

        return $connection->table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $connection->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
    }
};

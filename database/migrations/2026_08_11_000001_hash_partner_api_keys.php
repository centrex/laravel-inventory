<?php

declare(strict_types = 1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $connection = config('inventory.drivers.database.connection', config('database.default'));
        $schema = Schema::connection($connection);

        $table = $prefix . 'partners';

        if (!$schema->hasColumn($table, 'api_key_hash')) {
            $schema->table($table, function (Blueprint $table): void {
                $table->string('api_key_hash', 64)->nullable()->unique()->after('api_key');
            });
        }

        // Backfill: the existing api_key column is still plaintext at this point, so we can
        // hash it in place — no key rotation needed, partners keep using the same key.
        DB::connection($connection)->table($table)->orderBy('id')->chunkById(200, function ($rows) use ($connection, $table): void {
            foreach ($rows as $row) {
                if ($row->api_key !== null && $row->api_key !== '') {
                    DB::connection($connection)->table($table)->where('id', $row->id)->update([
                        'api_key_hash' => hash('sha256', $row->api_key),
                    ]);
                }
            }
        });

        if ($schema->hasColumn($table, 'api_key')) {
            $schema->table($table, function (Blueprint $table): void {
                $table->dropUnique(['api_key']);
                $table->dropColumn('api_key');
            });
        }
    }

    public function down(): void
    {
        $prefix = config('inventory.table_prefix', 'inv_');
        $connection = config('inventory.drivers.database.connection', config('database.default'));
        $schema = Schema::connection($connection);

        $table = $prefix . 'partners';

        // The plaintext key cannot be recovered from its hash — rolling back restores the
        // column but every partner needs a fresh key rotated afterward.
        if (!$schema->hasColumn($table, 'api_key')) {
            $schema->table($table, function (Blueprint $table): void {
                $table->string('api_key', 64)->nullable()->unique()->after('api_key_hash');
            });
        }

        if ($schema->hasColumn($table, 'api_key_hash')) {
            $schema->table($table, function (Blueprint $table): void {
                $table->dropUnique(['api_key_hash']);
                $table->dropColumn('api_key_hash');
            });
        }
    }
};

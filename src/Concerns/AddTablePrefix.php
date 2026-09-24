<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Concerns;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Resolves the configured table prefix and database connection for a package model.
 *
 * Both values are deploy-time constants, but Eloquent asks for them constantly —
 * getTable() runs at least once per hydrated row, and the connection name was
 * previously resolved in every model constructor (twice, since PHP evaluates
 * config()'s default argument eagerly). At ~6us per config() lookup that is real
 * time on any report that hydrates thousands of rows.
 *
 * The resolved values are memoised against the identity of the active config
 * repository rather than in a plain static, so a rebuilt application container —
 * every Testbench test case, every Octane request that flushes config — gets a
 * fresh lookup instead of inheriting the previous one's prefix/connection.
 */
trait AddTablePrefix
{
    /**
     * Keyed by spl_object_id() of the config repository the values were read from.
     *
     * @var array<int, array{prefix: string, connection: string|null}>
     */
    private static array $addTablePrefixSettings = [];

    public function getTable(): string
    {
        return self::inventorySchemaSettings()['prefix'] . $this->getTableSuffix();
    }

    /**
     * Falls back to the configured connection only when nothing has explicitly
     * called setConnection() on this instance, so a caller (or a test) pinning a
     * model to another connection still wins.
     */
    public function getConnectionName(): ?string
    {
        return $this->connection ?? self::inventorySchemaSettings()['connection'];
    }

    /**
     * @return array{prefix: string, connection: string|null}
     */
    private static function inventorySchemaSettings(): array
    {
        /** @var ConfigRepository $config */
        $config = app('config');

        $key = spl_object_id($config);

        if (!isset(self::$addTablePrefixSettings[$key])) {
            $prefix = $config->get('inventory.table_prefix');
            $connection = $config->get('inventory.drivers.database.connection')
                ?? $config->get('database.default');

            self::$addTablePrefixSettings[$key] = [
                'prefix'     => is_string($prefix) && $prefix !== '' ? $prefix : 'inv_',
                'connection' => is_string($connection) ? $connection : null,
            ];
        }

        return self::$addTablePrefixSettings[$key];
    }

    abstract protected function getTableSuffix(): string;
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Support;

final class TenantContext
{
    private static ?int $tenantId = null;

    private static ?\Closure $resolver = null;

    public static function set(?int $id): void
    {
        self::$tenantId = $id;
    }

    public static function get(): ?int
    {
        if (self::$tenantId !== null) {
            return self::$tenantId;
        }

        if (self::$resolver !== null) {
            return (self::$resolver)();
        }

        return null;
    }

    /** Register a callback that resolves the current tenant ID (e.g. from the request user). */
    public static function setResolver(\Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /** Run $callback with no tenant filter active, then restore previous state. */
    public static function withoutTenant(\Closure $callback): mixed
    {
        $prevId = self::$tenantId;
        $prevResolver = self::$resolver;

        self::$tenantId = null;
        self::$resolver = null;

        try {
            return $callback();
        } finally {
            self::$tenantId = $prevId;
            self::$resolver = $prevResolver;
        }
    }

    public static function clear(): void
    {
        self::$tenantId = null;
        self::$resolver = null;
    }
}

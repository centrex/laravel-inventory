<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Concerns;

use Centrex\Inventory\Scopes\TenantScope;
use Centrex\Inventory\Support\TenantContext;
use Illuminate\Database\Eloquent\{Builder, Model};

trait HasTenant
{
    public static function bootHasTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (Model $model): void {
            if ($model->getAttribute('tenant_id') === null) {
                $tenantId = TenantContext::get();
                if ($tenantId !== null) {
                    $model->setAttribute('tenant_id', $tenantId);
                }
            }
        });
    }

    /** Query records for a specific tenant, bypassing the global scope. */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class)
            ->where($this->getTable() . '.tenant_id', $tenantId);
    }

    /** Query records across all tenants (admin / artisan use). */
    public function scopeAllTenants(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}

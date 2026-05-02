<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Scopes;

use Centrex\Inventory\Support\TenantContext;
use Illuminate\Database\Eloquent\{Builder, Model, Scope};

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! config('inventory.tenant.enabled', true)) {
            return;
        }

        $tenantId = TenantContext::get();

        if ($tenantId !== null) {
            $column = config('inventory.tenant.column', 'tenant_id');
            $builder->where($model->getTable() . '.' . $column, $tenantId);
        }
    }
}

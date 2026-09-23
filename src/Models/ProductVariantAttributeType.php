<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class ProductVariantAttributeType extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;

    protected function getTableSuffix(): string
    {
        return 'product_variant_attribute_types';
    }

    protected $fillable = ['name', 'slug', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function values(): HasMany
    {
        return $this->hasMany(ProductVariantAttributeValue::class, 'attribute_type_id')
            ->orderBy('sort_order')
            ->orderBy('value');
    }
}

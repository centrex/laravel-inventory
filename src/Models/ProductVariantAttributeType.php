<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\{AddTablePrefix, HasTenant};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class ProductVariantAttributeType extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;
    use HasTenant;

    protected function getTableSuffix(): string
    {
        return 'product_variant_attribute_types';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
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

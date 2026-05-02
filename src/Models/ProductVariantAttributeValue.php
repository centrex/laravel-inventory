<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\{AddTablePrefix, HasTenant};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class ProductVariantAttributeValue extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;
    use HasTenant;

    protected function getTableSuffix(): string
    {
        return 'product_variant_attribute_values';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'attribute_type_id', 'value', 'display_value', 'color_hex', 'sort_order',
    ];

    protected $casts = ['sort_order' => 'integer'];

    public function attributeType(): BelongsTo
    {
        return $this->belongsTo(ProductVariantAttributeType::class, 'attribute_type_id');
    }

    public function getDisplayLabelAttribute(): string
    {
        return $this->display_value ?? $this->value;
    }
}

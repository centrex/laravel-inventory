<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\AddTablePrefix;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Partner extends Model implements Auditable
{
    use AddTablePrefix;
    use AuditableTrait;
    use SoftDeletes;

    protected function getTableSuffix(): string
    {
        return 'partners';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'name', 'type', 'api_key', 'customer_id', 'default_warehouse_id',
        'default_price_tier', 'can_view_stock', 'can_view_prices', 'can_create_orders',
        'is_active', 'allowed_warehouse_ids', 'allowed_product_ids',
    ];

    protected $hidden = ['api_key_hash'];

    /**
     * Only ever populated in-memory, right after generateApiKey() is assigned — never
     * persisted. Lets createPartner()/rotatePartnerApiKey() hand the plaintext key back to
     * the caller exactly once, without storing it anywhere.
     */
    private ?string $plainApiKey = null;

    protected $casts = [
        'can_view_stock'        => 'boolean',
        'can_view_prices'       => 'boolean',
        'can_create_orders'     => 'boolean',
        'is_active'             => 'boolean',
        'allowed_warehouse_ids' => 'array',
        'allowed_product_ids'   => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    public function canAccessWarehouse(int $warehouseId): bool
    {
        if ($this->allowed_warehouse_ids === null) {
            return true;
        }

        return in_array($warehouseId, $this->allowed_warehouse_ids, true);
    }

    public function canAccessProduct(int $productId): bool
    {
        if ($this->allowed_product_ids === null) {
            return true;
        }

        return in_array($productId, $this->allowed_product_ids, true);
    }

    public static function generateApiKey(): string
    {
        return 'inv_' . Str::random(60);
    }

    /**
     * Never persisted in plaintext — only the SHA-256 hash is written to api_key_hash.
     * The plaintext is kept on the in-memory $plainApiKey property so the caller (see
     * Inventory::createPartner()/rotatePartnerApiKey()) can return it exactly once.
     */
    public function setApiKeyAttribute(string $value): void
    {
        $this->plainApiKey = $value;
        $this->attributes['api_key_hash'] = hash('sha256', $value);
    }

    public function getPlainApiKey(): ?string
    {
        return $this->plainApiKey;
    }

    public static function findByApiKey(string $apiKey): ?self
    {
        return static::where('api_key_hash', hash('sha256', $apiKey))->first();
    }
}

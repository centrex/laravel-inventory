<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Models;

use Centrex\Inventory\Concerns\{AddTablePrefix, HasTenant};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommercialTeamMember extends Model
{
    use AddTablePrefix;
    use HasTenant;

    protected function getTableSuffix(): string
    {
        return 'commercial_team_members';
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setConnection(config('inventory.drivers.database.connection', config('database.default')));
    }

    protected $fillable = [
        'workflow',
        'user_id',
        'manager_user_id',
        'role',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'meta'      => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo((string) config('auth.providers.users.model', 'App\\Models\\User'));
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo((string) config('auth.providers.users.model', 'App\\Models\\User'), 'manager_user_id');
    }

    public function managerUser(): BelongsTo
    {
        return $this->manager();
    }
}

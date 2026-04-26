<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Support;

use Centrex\Inventory\Models\CommercialTeamMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Gate, Schema};

final class CommercialTeamAccess
{
    public const ROLE_MANAGER = 'manager';
    public const ROLE_ASSISTANT_MANAGER = 'assistant_manager';
    public const ROLE_EXECUTIVE = 'executive';

    public static function roleOptions(): array
    {
        return [
            ['code' => self::ROLE_MANAGER, 'name' => 'Manager'],
            ['code' => self::ROLE_ASSISTANT_MANAGER, 'name' => 'Assistant Manager'],
            ['code' => self::ROLE_EXECUTIVE, 'name' => 'Executive'],
        ];
    }

    public static function workflowOptions(): array
    {
        return [
            ['code' => 'sales', 'name' => 'Sales'],
            ['code' => 'purchase', 'name' => 'Purchase'],
        ];
    }

    public static function applySalesScope(Builder $query): Builder
    {
        return self::applyOwnerScope($query, 'sales', [
            'created_by',
            'sales_owner_id',
            'sales_manager_id',
            'sales_assistant_manager_id',
            'sales_executive_id',
        ]);
    }

    public static function applyPurchaseScope(Builder $query): Builder
    {
        return self::applyOwnerScope($query, 'purchase', [
            'created_by',
            'purchase_manager_id',
            'purchase_assistant_manager_id',
            'purchase_executive_id',
        ]);
    }

    public static function assignmentFor(string $workflow, ?int $userId = null): array
    {
        $userId ??= self::currentUserId();

        if (!$userId || !self::tableReady()) {
            return [];
        }

        $member = CommercialTeamMember::query()
            ->where('workflow', $workflow)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->orderByRaw("CASE role WHEN 'executive' THEN 1 WHEN 'assistant_manager' THEN 2 WHEN 'manager' THEN 3 ELSE 4 END")
            ->first();

        if (!$member) {
            return [];
        }

        if ($member->role === self::ROLE_MANAGER) {
            return [
                "{$workflow}_manager_id" => $userId,
            ];
        }

        if ($member->role === self::ROLE_ASSISTANT_MANAGER) {
            return [
                "{$workflow}_manager_id"           => $member->manager_user_id,
                "{$workflow}_assistant_manager_id" => $userId,
            ];
        }

        $assistant = $member->manager_user_id
            ? CommercialTeamMember::query()
                ->where('workflow', $workflow)
                ->where('user_id', $member->manager_user_id)
                ->where('role', self::ROLE_ASSISTANT_MANAGER)
                ->where('is_active', true)
                ->first()
            : null;

        return [
            "{$workflow}_manager_id"           => $assistant?->manager_user_id ?: $member->manager_user_id,
            "{$workflow}_assistant_manager_id" => $assistant?->user_id,
            "{$workflow}_executive_id"         => $userId,
        ];
    }

    public static function assignmentForUserDesignation(string $workflow, ?int $userId, ?string $designation): array
    {
        $designation = self::normalizeRole($designation);

        $empty = [
            "{$workflow}_manager_id"           => null,
            "{$workflow}_assistant_manager_id" => null,
            "{$workflow}_executive_id"         => null,
        ];

        if (!$userId || !$designation) {
            return $empty;
        }

        if ($designation === self::ROLE_MANAGER) {
            return [
                ...$empty,
                "{$workflow}_manager_id" => $userId,
            ];
        }

        if (!self::tableReady()) {
            return [
                ...$empty,
                "{$workflow}_{$designation}_id" => $userId,
            ];
        }

        $member = CommercialTeamMember::query()
            ->where('workflow', $workflow)
            ->where('user_id', $userId)
            ->where('role', $designation)
            ->where('is_active', true)
            ->first();

        if ($designation === self::ROLE_ASSISTANT_MANAGER) {
            return [
                ...$empty,
                "{$workflow}_manager_id"           => $member?->manager_user_id,
                "{$workflow}_assistant_manager_id" => $userId,
            ];
        }

        $assistant = $member?->manager_user_id
            ? CommercialTeamMember::query()
                ->where('workflow', $workflow)
                ->where('user_id', $member->manager_user_id)
                ->where('role', self::ROLE_ASSISTANT_MANAGER)
                ->where('is_active', true)
                ->first()
            : null;

        return [
            ...$empty,
            "{$workflow}_manager_id"           => $assistant?->manager_user_id ?: $member?->manager_user_id,
            "{$workflow}_assistant_manager_id" => $assistant?->user_id,
            "{$workflow}_executive_id"         => $userId,
        ];
    }

    public static function visibleUserIds(string $workflow, ?int $userId = null): ?array
    {
        $userId ??= self::currentUserId();

        if (!$userId || self::isPrivilegedUser($userId) || !self::tableReady() || !self::workflowHasActiveMembers($workflow)) {
            return null;
        }

        $memberships = CommercialTeamMember::query()
            ->where('workflow', $workflow)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->get();

        if ($memberships->isEmpty()) {
            return [$userId];
        }

        $visible = collect([$userId]);

        foreach ($memberships as $membership) {
            $visible = $visible->merge(self::descendantUserIds($workflow, $membership->user_id, $membership->role));
        }

        return $visible->filter()->unique()->values()->map(fn ($id): int => (int) $id)->all();
    }

    private static function applyOwnerScope(Builder $query, string $workflow, array $columns): Builder
    {
        $visibleUserIds = self::visibleUserIds($workflow);

        if ($visibleUserIds === null) {
            return $query;
        }

        $model = $query->getModel();
        $table = $model->getTable();

        return $query->where(function (Builder $builder) use ($columns, $table, $visibleUserIds): void {
            foreach ($columns as $column) {
                if (Schema::connection($builder->getModel()->getConnectionName())->hasColumn($table, $column)) {
                    $builder->orWhereIn($column, $visibleUserIds);
                }
            }
        });
    }

    private static function descendantUserIds(string $workflow, int $userId, string $role): Collection
    {
        if ($role === self::ROLE_EXECUTIVE) {
            return collect();
        }

        $direct = CommercialTeamMember::query()
            ->where('workflow', $workflow)
            ->where('manager_user_id', $userId)
            ->where('is_active', true)
            ->get(['user_id', 'role']);

        $visible = $direct->pluck('user_id');

        if ($role === self::ROLE_MANAGER) {
            $assistantIds = $direct
                ->where('role', self::ROLE_ASSISTANT_MANAGER)
                ->pluck('user_id')
                ->all();

            if ($assistantIds !== []) {
                $visible = $visible->merge(
                    CommercialTeamMember::query()
                        ->where('workflow', $workflow)
                        ->whereIn('manager_user_id', $assistantIds)
                        ->where('is_active', true)
                        ->pluck('user_id'),
                );
            }
        }

        return $visible;
    }

    private static function isPrivilegedUser(int $userId): bool
    {
        $userClass = (string) config('auth.providers.users.model', 'App\\Models\\User');

        if (!class_exists($userClass)) {
            return false;
        }

        $user = $userClass::query()->find($userId);

        if (!$user) {
            return false;
        }

        if (Gate::has('inventory-admin') && Gate::forUser($user)->allows('inventory-admin')) {
            return true;
        }

        return method_exists($user, 'hasRole') && $user->hasRole(self::adminRoles());
    }

    private static function workflowHasActiveMembers(string $workflow): bool
    {
        return CommercialTeamMember::query()
            ->where('workflow', $workflow)
            ->where('is_active', true)
            ->exists();
    }

    private static function adminRoles(): array
    {
        $roles = config('inventory.admin_roles', []);

        if (is_array($roles)) {
            return array_values(array_filter(array_map('strval', $roles)));
        }

        if (!is_string($roles) || trim($roles) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $roles))));
    }

    private static function normalizeRole(mixed $role): ?string
    {
        $normalized = trim((string) $role);

        return in_array($normalized, [self::ROLE_MANAGER, self::ROLE_ASSISTANT_MANAGER, self::ROLE_EXECUTIVE], true)
            ? $normalized
            : null;
    }

    private static function currentUserId(): ?int
    {
        return auth()->id() ? (int) auth()->id() : null;
    }

    private static function tableReady(): bool
    {
        $model = new CommercialTeamMember();

        return Schema::connection($model->getConnectionName())->hasTable($model->getTable());
    }
}

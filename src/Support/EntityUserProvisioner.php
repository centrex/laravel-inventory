<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * Creates a login user for a newly created customer or supplier and links it
 * back to the record through the `modelable` morph, when the "create login
 * user account" option was selected on the form / API payload.
 */
class EntityUserProvisioner
{
    /** Entity => [display-name attribute, email attribute] used to seed the user. */
    private const SUPPORTED = [
        'customers' => ['name' => 'name', 'email' => 'email'],
        'suppliers' => ['name' => 'contact_name', 'email' => 'contact_email'],
    ];

    public static function emailField(string $entity): ?string
    {
        return self::SUPPORTED[$entity]['email'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $payload  validated payload containing the
     *                                         virtual create_user / user_password fields
     */
    public static function provision(string $entity, Model $record, array $payload): ?Model
    {
        if (!isset(self::SUPPORTED[$entity]) || empty($payload['create_user'])) {
            return null;
        }

        $fields = self::SUPPORTED[$entity];
        $email = $record->getAttribute($fields['email']);

        if (!is_string($email) || $email === '') {
            return null;
        }

        /** @var class-string<Model> $userModel */
        $userModel = (string) config('auth.providers.users.model', 'App\\Models\\User');

        $user = new $userModel();
        $user->forceFill([
            'name'     => $record->getAttribute($fields['name']) ?: $record->getAttribute('name'),
            'email'    => $email,
            'password' => Hash::make((string) $payload['user_password']),
        ])->save();

        $record->forceFill([
            'modelable_type' => $user->getMorphClass(),
            'modelable_id'   => $user->getKey(),
        ])->save();

        return $user;
    }

    /** The login user currently linked to this customer/supplier record, if any. */
    public static function linkedUser(string $entity, Model $record): ?Model
    {
        if (!isset(self::SUPPORTED[$entity])) {
            return null;
        }

        return $record->modelable;
    }

    /** The email value that would seed a new login user for this record, if one is set. */
    public static function resolvedEmail(string $entity, Model $record): ?string
    {
        $fields = self::SUPPORTED[$entity] ?? null;

        if ($fields === null) {
            return null;
        }

        $email = $record->getAttribute($fields['email']);

        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * Create a brand-new login user for an already-existing customer/supplier record
     * and link it — the same seeding logic used at creation time, reachable after the
     * fact for records that were created without the "create login user" option.
     */
    public static function createAndLink(string $entity, Model $record, string $password): ?Model
    {
        return self::provision($entity, $record, ['create_user' => true, 'user_password' => $password]);
    }

    /** Link an existing user account (by id, on the configured auth model) to this record. */
    public static function linkExisting(string $entity, Model $record, int $userId): ?Model
    {
        if (!isset(self::SUPPORTED[$entity])) {
            return null;
        }

        /** @var class-string<Model> $userModel */
        $userModel = (string) config('auth.providers.users.model', 'App\\Models\\User');
        $user = $userModel::query()->findOrFail($userId);

        $record->forceFill([
            'modelable_type' => $user->getMorphClass(),
            'modelable_id'   => $user->getKey(),
        ])->save();

        return $user;
    }

    /** Unlink whatever login user is currently associated with this record (the login account itself is untouched). */
    public static function unlink(string $entity, Model $record): void
    {
        if (!isset(self::SUPPORTED[$entity])) {
            return;
        }

        $record->forceFill([
            'modelable_type' => null,
            'modelable_id'   => null,
        ])->save();
    }
}

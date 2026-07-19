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
}

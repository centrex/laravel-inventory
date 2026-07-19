<?php

declare(strict_types = 1);

use Centrex\Inventory\Models\{Customer, Supplier};
use Centrex\Inventory\Support\{EntityUserProvisioner, InventoryEntityRegistry};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Hash, Schema};

beforeEach(function (): void {
    if (!Schema::hasTable('users')) {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }
});

it('creates and links a login user when create_user is set on a new customer', function (): void {
    $customer = Customer::create([
        'code'     => 'C-100',
        'name'     => 'Acme Corp',
        'email'    => 'acme@example.com',
        'currency' => 'BDT',
    ]);

    $user = EntityUserProvisioner::provision('customers', $customer, [
        'create_user'   => true,
        'user_password' => 'secret-pass',
    ]);

    expect($user)->not->toBeNull()
        ->and($user->email)->toBe('acme@example.com')
        ->and($user->name)->toBe('Acme Corp')
        ->and(Hash::check('secret-pass', $user->password))->toBeTrue();

    $customer->refresh();
    expect($customer->modelable_type)->toBe($user->getMorphClass())
        ->and((int) $customer->modelable_id)->toBe((int) $user->getKey());
});

it('creates and links a login user from supplier contact details', function (): void {
    $supplier = Supplier::create([
        'code'          => 'S-100',
        'name'          => 'Supplier Ltd',
        'contact_name'  => 'Jamal Uddin',
        'contact_email' => 'jamal@supplier.example',
        'currency'      => 'BDT',
    ]);

    $user = EntityUserProvisioner::provision('suppliers', $supplier, [
        'create_user'   => true,
        'user_password' => 'secret-pass',
    ]);

    expect($user)->not->toBeNull()
        ->and($user->email)->toBe('jamal@supplier.example')
        ->and($user->name)->toBe('Jamal Uddin');

    $supplier->refresh();
    expect($supplier->modelable_id)->not->toBeNull();
});

it('does not create a user when create_user is not set', function (): void {
    $customer = Customer::create([
        'code'     => 'C-101',
        'name'     => 'No User Corp',
        'email'    => 'nouser@example.com',
        'currency' => 'BDT',
    ]);

    $user = EntityUserProvisioner::provision('customers', $customer, ['create_user' => false]);

    expect($user)->toBeNull()
        ->and($customer->refresh()->modelable_id)->toBeNull();
});

it('excludes the virtual user fields from the persistable payload', function (): void {
    expect(InventoryEntityRegistry::virtualFieldNames('customers'))->toBe(['create_user', 'user_password'])
        ->and(InventoryEntityRegistry::virtualFieldNames('suppliers'))->toBe(['create_user', 'user_password'])
        ->and(InventoryEntityRegistry::virtualFieldNames('warehouses'))->toBe([]);
});

it('requires email, unique login email and password when create_user is checked', function (): void {
    $rules = InventoryEntityRegistry::validationRules('customers', null, ['create_user' => true]);

    expect($rules['email'])->toContain('required')
        ->and($rules['email'])->not->toContain('nullable')
        ->and(collect($rules['email'])->contains(fn ($rule) => $rule instanceof Illuminate\Validation\Rules\Unique))->toBeTrue()
        ->and($rules['user_password'])->toContain('required_if:create_user,true');

    $validator = validator(
        ['create_user' => true, 'user_password' => null],
        ['user_password' => $rules['user_password']],
    );

    expect($validator->fails())->toBeTrue();
});

it('keeps email optional when create_user is not checked', function (): void {
    $rules = InventoryEntityRegistry::validationRules('customers', null, ['create_user' => false]);

    expect($rules['email'])->toContain('nullable');
});

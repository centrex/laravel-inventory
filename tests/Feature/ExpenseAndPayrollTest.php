<?php

declare(strict_types = 1);

use Centrex\Inventory\Models\{Employee, PayrollAccount};

it('exposes inventory expense and payroll routes', function (): void {
    $this->get('/inventory/expenses')->assertOk();
    $this->get('/inventory/payroll')->assertOk();
    $this->get('/inventory/employees')->assertOk();
});

it('creates payroll entries through the api', function (): void {
    $employee = Employee::create([
        'code'      => 'EMP-001',
        'name'      => 'Rahim Uddin',
        'currency'  => 'BDT',
        'is_active' => true,
    ]);

    $account = PayrollAccount::create([
        'code'      => 'BASIC',
        'name'      => 'Basic Salary',
        'currency'  => 'BDT',
        'is_active' => true,
    ]);

    $response = $this->postJson('/api/inventory/payroll-entries', [
        'date'      => '2026-04-12',
        'type'      => 'salary',
        'reference' => 'APR-2026',
        'lines'     => [[
            'employee_id'        => $employee->id,
            'payroll_account_id' => $account->id,
            'amount'             => 25000,
            'description'        => 'Monthly salary',
        ]],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.type', 'salary')
        ->assertJsonPath('data.employee_count', 1)
        ->assertJsonPath('data.lines.0.employee_id', $employee->id)
        ->assertJsonPath('data.lines.0.payroll_account_id', $account->id);
});

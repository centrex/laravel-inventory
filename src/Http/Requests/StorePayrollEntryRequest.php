<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePayrollEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date'                       => ['required', 'date'],
            'reference'                  => ['nullable', 'string', 'max:255'],
            'description'                => ['nullable', 'string'],
            'currency'                   => ['nullable', 'string', 'size:3'],
            'type'                       => ['required', 'string', 'in:salary,bonus,deduction,tax,adjustment'],
            'exchange_rate'              => ['nullable', 'numeric', 'gt:0'],
            'lines'                      => ['required', 'array', 'min:1'],
            'lines.*.employee_id'        => ['required', 'integer'],
            'lines.*.payroll_account_id' => ['required', 'integer'],
            'lines.*.amount'             => ['required', 'numeric', 'gt:0'],
            'lines.*.description'        => ['nullable', 'string', 'max:500'],
            'lines.*.reference'          => ['nullable', 'string', 'max:255'],
        ];
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_id'          => ['nullable', 'integer'],
            'expense_date'        => ['required', 'date'],
            'due_date'            => ['nullable', 'date', 'after_or_equal:expense_date'],
            'currency'            => ['nullable', 'string', 'size:3'],
            'payment_method'      => ['nullable', 'string', 'in:cash,check,bank_transfer,card,credit'],
            'reference'           => ['nullable', 'string'],
            'vendor_name'         => ['nullable', 'string', 'max:255'],
            'notes'               => ['nullable', 'string'],
            'items'               => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity'    => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price'  => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate'    => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}

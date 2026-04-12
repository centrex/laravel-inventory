<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollEntryLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'employee_id' => $this->employee_id,
            'employee'    => $this->whenLoaded('employee', fn () => [
                'id'          => $this->employee?->id,
                'code'        => $this->employee?->code,
                'name'        => $this->employee?->name,
                'department'  => $this->employee?->department,
                'designation' => $this->employee?->designation,
            ]),
            'payroll_account_id' => $this->payroll_account_id,
            'payroll_account'    => $this->whenLoaded('payrollAccount', fn () => [
                'id'   => $this->payrollAccount?->id,
                'code' => $this->payrollAccount?->code,
                'name' => $this->payrollAccount?->name,
            ]),
            'amount'      => (float) $this->amount,
            'description' => $this->description,
            'reference'   => $this->reference,
        ];
    }
}

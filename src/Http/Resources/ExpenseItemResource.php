<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'expense_id'  => $this->expense_id,
            'description' => $this->description,
            'quantity'    => $this->quantity,
            'unit_price'  => $this->unit_price,
            'amount'      => $this->amount,
            'tax_rate'    => $this->tax_rate,
            'tax_amount'  => $this->tax_amount,
            'created_at'  => $this->created_at?->toISOString(),
        ];
    }
}

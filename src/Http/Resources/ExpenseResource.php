<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'expense_number' => $this->expense_number,
            'account_id'     => $this->account_id,
            'expense_date'   => $this->expense_date?->toDateString(),
            'due_date'       => $this->due_date?->toDateString(),
            'subtotal'       => $this->subtotal,
            'tax_amount'     => $this->tax_amount,
            'total'          => $this->total,
            'paid_amount'    => $this->paid_amount,
            'balance'        => $this->balance,
            'currency'       => $this->currency,
            'status'         => $this->status,
            'payment_method' => $this->payment_method,
            'reference'      => $this->reference,
            'vendor_name'    => $this->vendor_name,
            'notes'          => $this->notes,
            'items'          => ExpenseItemResource::collection($this->whenLoaded('items')),
            'created_at'     => $this->created_at?->toISOString(),
            'updated_at'     => $this->updated_at?->toISOString(),
        ];
    }
}

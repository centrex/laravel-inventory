<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'entry_number'   => $this->entry_number,
            'date'           => $this->date?->toDateString(),
            'reference'      => $this->reference,
            'description'    => $this->description,
            'currency'       => $this->currency,
            'type'           => $this->type,
            'exchange_rate'  => (float) $this->exchange_rate,
            'status'         => $this->status,
            'approved_by'    => $this->approved_by,
            'approved_at'    => $this->approved_at?->toDateTimeString(),
            'total_amount'   => (float) $this->lines->sum('amount'),
            'employee_count' => $this->whenLoaded('lines', fn (): int => $this->lines
                ->pluck('employee_id')
                ->filter()
                ->unique()
                ->count(), 0),
            'lines' => PayrollEntryLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}

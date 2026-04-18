<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                          => $this->id,
            'grn_number'                  => $this->grn_number,
            'purchase_order_id'           => $this->purchase_order_id,
            'warehouse_id'                => $this->warehouse_id,
            'status'                      => $this->status,
            'received_at'                 => $this->received_at?->toIso8601String(),
            'notes'                       => $this->notes,
            'created_by'                  => $this->created_by,
            'accounting_journal_entry_id' => $this->accounting_journal_entry_id,
            'created_at'                  => $this->created_at?->toIso8601String(),
            'updated_at'                  => $this->updated_at?->toIso8601String(),
            'items'                       => StockReceiptItemResource::collection($this->whenLoaded('items')),
        ];
    }
}

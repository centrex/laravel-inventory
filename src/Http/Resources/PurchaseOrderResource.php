<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'po_number'            => $this->po_number,
            'warehouse_id'         => $this->warehouse_id,
            'supplier_id'          => $this->supplier_id,
            'currency'             => $this->currency,
            'exchange_rate'        => $this->exchange_rate,
            'subtotal_local'       => $this->subtotal_local,
            'subtotal_amount'      => $this->subtotal_amount,
            'tax_local'            => $this->tax_local,
            'tax_amount'           => $this->tax_amount,
            'shipping_local'       => $this->shipping_local,
            'shipping_amount'      => $this->shipping_amount,
            'other_charges_amount' => $this->other_charges_amount,
            'total_local'          => $this->total_local,
            'total_amount'         => $this->total_amount,
            'status'               => $this->status,
            'ordered_at'           => $this->ordered_at?->toIso8601String(),
            'expected_at'          => $this->expected_at?->toDateString(),
            'notes'                => $this->notes,
            'created_by'           => $this->created_by,
            'accounting_bill_id'   => $this->accounting_bill_id,
            'created_at'           => $this->created_at?->toIso8601String(),
            'updated_at'           => $this->updated_at?->toIso8601String(),
            'items'                => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
        ];
    }
}

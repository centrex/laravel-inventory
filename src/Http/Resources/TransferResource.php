<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'transfer_number'      => $this->transfer_number,
            'from_warehouse_id'    => $this->from_warehouse_id,
            'to_warehouse_id'      => $this->to_warehouse_id,
            'status'               => $this->status,
            'total_weight_kg'      => $this->total_weight_kg,
            'shipping_rate_per_kg' => $this->shipping_rate_per_kg,
            'shipping_cost_amount' => $this->shipping_cost_amount,
            'notes'                => $this->notes,
            'shipped_at'           => $this->shipped_at?->toIso8601String(),
            'received_at'          => $this->received_at?->toIso8601String(),
            'created_by'           => $this->created_by,
            'created_at'           => $this->created_at?->toIso8601String(),
            'updated_at'           => $this->updated_at?->toIso8601String(),
            'boxes'                => TransferBoxResource::collection($this->whenLoaded('boxes')),
            'items'                => TransferItemResource::collection($this->whenLoaded('items')),
        ];
    }
}

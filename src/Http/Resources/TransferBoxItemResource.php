<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransferBoxItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                        => $this->id,
            'transfer_box_id'           => $this->transfer_box_id,
            'product_id'                => $this->product_id,
            'qty_sent'                  => $this->qty_sent,
            'theoretical_weight_kg'     => $this->theoretical_weight_kg,
            'allocated_weight_kg'       => $this->allocated_weight_kg,
            'weight_ratio'              => $this->weight_ratio,
            'source_unit_cost_amount'   => $this->source_unit_cost_amount,
            'shipping_allocated_amount' => $this->shipping_allocated_amount,
            'unit_landed_cost_amount'   => $this->unit_landed_cost_amount,
            'notes'                     => $this->notes,
            'product'                   => new ProductResource($this->whenLoaded('product')),
        ];
    }
}

<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdjustmentItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'adjustment_id'    => $this->adjustment_id,
            'product_id'       => $this->product_id,
            'qty_system'       => $this->qty_system,
            'qty_actual'       => $this->qty_actual,
            'qty_delta'        => $this->qty_delta,
            'unit_cost_amount' => $this->unit_cost_amount,
            'notes'            => $this->notes,
            'product'          => new ProductResource($this->whenLoaded('product')),
        ];
    }
}

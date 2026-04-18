<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'sale_order_id'     => $this->sale_order_id,
            'product_id'        => $this->product_id,
            'price_tier_id'     => $this->price_tier_id,
            'qty_ordered'       => $this->qty_ordered,
            'qty_fulfilled'     => $this->qty_fulfilled,
            'unit_price_local'  => $this->unit_price_local,
            'unit_price_amount' => $this->unit_price_amount,
            'unit_cost_amount'  => $this->unit_cost_amount,
            'discount_pct'      => $this->discount_pct,
            'line_total_local'  => $this->line_total_local,
            'line_total_amount' => $this->line_total_amount,
            'notes'             => $this->notes,
            'product'           => new ProductResource($this->whenLoaded('product')),
        ];
    }
}
